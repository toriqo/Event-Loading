<?php

declare(strict_types=1);

namespace App\EventLoading;

interface EventSourceInterface
{
    public function name(): string;

    /**
     * @return list<Event> Sorted by id in ascending order.
     */
    public function fetchAfter(int $lastKnownEventId, int $limit = 1000): array;
}

