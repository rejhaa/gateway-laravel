<?php

declare(strict_types=1);

namespace App\Gateway;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves a process by its name from config('gateway.processes') through the
 * container, so a process gets its dependencies injected.
 */
final class Processes
{
    public function __construct(private readonly Container $container) {}

    /** The process the gateway runs now, config('gateway.process'). */
    public function current(): Process
    {
        return $this->named((string) config('gateway.process'));
    }

    public function named(string $name): Process
    {
        $class = config('gateway.processes')[$name] ?? null;

        if (! is_string($class)) {
            throw new InvalidArgumentException("No process named {$name} is configured in gateway.processes.");
        }

        return $this->container->make($class);
    }
}
