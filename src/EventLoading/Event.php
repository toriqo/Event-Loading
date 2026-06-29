<?php

declare(strict_types=1);

namespace App\EventLoading;

final readonly class Event
{
    public function __construct(
        public int $id,
        public array $payload = [],
    ) {
    }
}

