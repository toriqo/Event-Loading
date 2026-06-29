<?php

declare(strict_types=1);

namespace App\EventLoading;

interface SourcePollingCoordinatorInterface
{
    /**
     * Grants an exclusive right to poll a source and enforces the minimum interval
     * between requests globally (across all loader instances).
     */
    public function acquire(string $sourceName, int $minimumIntervalMs): SourcePollingToken;

    /**
     * Commits progress and releases the right to poll this source.
     */
    public function commit(SourcePollingToken $token, int $lastStoredEventId): void;

    /**
     * Releases the right to poll this source without advancing the cursor.
     */
    public function release(SourcePollingToken $token): void;
}

