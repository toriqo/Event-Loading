<?php

declare(strict_types=1);

use App\EventLoading\Event;
use App\EventLoading\EventSourceInterface;
use App\EventLoading\EventStoreInterface;
use App\EventLoading\RoundRobinEventLoader;
use App\EventLoading\SourcePollingCoordinatorInterface;
use App\EventLoading\SourcePollingToken;
use Psr\Log\AbstractLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

final class InMemoryCoordinator implements SourcePollingCoordinatorInterface
{
    /** @var array<string, int> */
    private array $lastStoredEventIdBySource = [];

    /** @var array<string, int> */
    private array $nextAllowedTimestampMsBySource = [];

    /** @var array<string, bool> */
    private array $lockedSources = [];

    public function acquire(string $sourceName, int $minimumIntervalMs): SourcePollingToken
    {
        while (($this->lockedSources[$sourceName] ?? false) === true) {
            usleep(1_000);
        }
        $this->lockedSources[$sourceName] = true;

        $nowMs = (int) floor(microtime(true) * 1000);
        $nextAllowed = $this->nextAllowedTimestampMsBySource[$sourceName] ?? $nowMs;
        $sleepMs = $nextAllowed - $nowMs;
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $this->nextAllowedTimestampMsBySource[$sourceName] = (int) floor(microtime(true) * 1000) + $minimumIntervalMs;

        return new SourcePollingToken($sourceName, $this->lastStoredEventIdBySource[$sourceName] ?? 0);
    }

    public function commit(SourcePollingToken $token, int $lastStoredEventId): void
    {
        $current = $this->lastStoredEventIdBySource[$token->sourceName] ?? 0;
        if ($lastStoredEventId < $current) {
            throw new RuntimeException('Cursor cannot move backwards.');
        }

        $this->lastStoredEventIdBySource[$token->sourceName] = $lastStoredEventId;
        $this->lockedSources[$token->sourceName] = false;
    }

    public function release(SourcePollingToken $token): void
    {
        $this->lockedSources[$token->sourceName] = false;
    }
}

final class DemoSource implements EventSourceInterface
{
    /** @var list<Event> */
    private array $events;

    /**
     * @param list<Event> $events
     */
    public function __construct(
        private string $name,
        array $events,
        private bool $failOnFirstCall = false,
    ) {
        $this->events = $events;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function fetchAfter(int $lastKnownEventId, int $limit = 1000): array
    {
        if ($this->failOnFirstCall) {
            $this->failOnFirstCall = false;
            throw new RuntimeException('Temporary network error');
        }

        $result = [];
        foreach ($this->events as $event) {
            if ($event->id <= $lastKnownEventId) {
                continue;
            }
            $result[] = $event;
            if (count($result) === $limit) {
                break;
            }
        }

        return $result;
    }
}

final class CollectingStore implements EventStoreInterface
{
    /** @var array<string, array<int, bool>> */
    public array $storedIdsBySource = [];

    public function store(string $sourceName, array $events): void
    {
        foreach ($events as $event) {
            if (isset($this->storedIdsBySource[$sourceName][$event->id])) {
                throw new RuntimeException(sprintf('Duplicate event transported: %s#%d', $sourceName, $event->id));
            }
            $this->storedIdsBySource[$sourceName][$event->id] = true;
        }
    }
}

final class EchoLogger extends AbstractLogger
{
    public function log($level, Stringable|string $message, array $context = []): void
    {
        echo sprintf("[%s] %s\n", strtoupper((string) $level), (string) $message);
    }
}

$coordinator = new InMemoryCoordinator();
$store = new CollectingStore();
$logger = new EchoLogger();

$sourceA = new DemoSource(
    'alpha',
    array_map(static fn (int $id) => new Event($id), range(1, 6))
);
$sourceB = new DemoSource(
    'beta',
    array_map(static fn (int $id) => new Event($id), range(1, 4)),
    failOnFirstCall: true
);

$loader1 = new RoundRobinEventLoader([$sourceA, $sourceB], $store, $coordinator, $logger, minIntervalMs: 200, batchSize: 2);
$loader2 = new RoundRobinEventLoader([$sourceA, $sourceB], $store, $coordinator, $logger, minIntervalMs: 200, batchSize: 2);

for ($i = 0; $i < 5; $i++) {
    $loader1->runForIterations(1);
    $loader2->runForIterations(1);
}

echo "Stored unique events:\n";
foreach ($store->storedIdsBySource as $sourceName => $ids) {
    ksort($ids);
    echo sprintf("- %s: %s\n", $sourceName, implode(', ', array_keys($ids)));
}
