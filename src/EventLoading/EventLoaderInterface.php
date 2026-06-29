<?php

declare(strict_types=1);

namespace App\EventLoading;

interface EventLoaderInterface
{
    public function run(): never;
}

