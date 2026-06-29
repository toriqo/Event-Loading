<?php

declare(strict_types=1);

namespace App\EventLoading;

use Psr\Log\LoggerInterface;
use Throwable;

final class RoundRobinEventLoader implements EventLoaderInterface
{
    /**
     * @param list<EventSourceInterface> $sources
     */
    public function __construct(
        private array $sources,
        private EventStoreInterface $store,
        private SourcePollingCoordinatorInterface $coordinator,
        private LoggerInterface $logger,
        private int $minIntervalMs = 200,
        private int $batchSize = 1000,
    ) {
        if ($sources === []) {
            throw new \InvalidArgumentException('At least one source is required.');
        }
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException('Batch size must be between 1 and 1000.');
        }
    }

    public function run(): never
    {
        while (true) {
            $this->runSingleIteration();
        }
    }

    public function runForIterations(int $iterations): void
    {
        if ($iterations < 1) {
            throw new \InvalidArgumentException('Iterations must be greater than zero.');
        }

        for ($i = 0; $i < $iterations; $i++) {
            $this->runSingleIteration();
        }
    }

    private function runSingleIteration(): void
    {
        foreach ($this->sources as $source) {
            $token = $this->coordinator->acquire($source->name(), $this->minIntervalMs);

            try {
                $events = $source->fetchAfter($token->lastStoredEventId, $this->batchSize);
                $this->store->store($source->name(), $events);

                $lastStoredEventId = $token->lastStoredEventId;
                if ($events !== []) {
                    $lastStoredEventId = $events[array_key_last($events)]->id;
                }

                $this->coordinator->commit($token, $lastStoredEventId);
            } catch (Throwable $exception) {
                $this->coordinator->release($token);
                $this->logger->warning(
                    sprintf('Source "%s" is unavailable: %s', $source->name(), $exception->getMessage()),
                    ['source' => $source->name(), 'exception' => $exception]
                );
            }
        }
    }
}

