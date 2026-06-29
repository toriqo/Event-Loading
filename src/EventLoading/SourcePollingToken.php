<?php

declare(strict_types=1);

namespace App\EventLoading;

final readonly class SourcePollingToken
{
    public function __construct(
        public string $sourceName,
        public int $lastStoredEventId,
    ) {
    }
}

