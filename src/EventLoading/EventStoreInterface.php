<?php

declare(strict_types=1);

namespace App\EventLoading;

interface EventStoreInterface
{
    /**
     * @param list<Event> $events
     */
    public function store(string $sourceName, array $events): void;
}

