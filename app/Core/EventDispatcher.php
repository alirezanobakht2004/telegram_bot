<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use Closure;

final class EventDispatcher
{
    /**
     * @var array<string, list<array{priority: int, listener: Closure}>>
     */
    private array $listeners = [];

    /**
     * @param callable(UpdateContext, string): void $listener
     */
    public function listen(
        string $event,
        callable $listener,
        int $priority = 0
    ): self {
        $event = trim($event);

        if ($event === '') {
            $event = '*';
        }

        $this->listeners[$event][] = [
            'priority' => $priority,
            'listener' => Closure::fromCallable($listener),
        ];

        usort(
            $this->listeners[$event],
            static fn (array $left, array $right): int =>
                $right['priority'] <=> $left['priority']
        );

        return $this;
    }

    public function dispatch(
        string $event,
        UpdateContext $context
    ): void {
        if ($context->isPropagationStopped()) {
            return;
        }

        $listeners = $event === '*'
            ? ($this->listeners['*'] ?? [])
            : [
                ...($this->listeners[$event] ?? []),
                ...($this->listeners['*'] ?? []),
            ];

        usort(
            $listeners,
            static fn (array $left, array $right): int =>
                $right['priority'] <=> $left['priority']
        );

        foreach ($listeners as $entry) {
            ($entry['listener'])($context, $event);

            if ($context->isPropagationStopped()) {
                return;
            }
        }
    }
}
