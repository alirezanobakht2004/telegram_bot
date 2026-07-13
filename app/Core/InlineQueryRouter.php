<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use Closure;
use ReflectionFunction;
use Throwable;

final class InlineQueryRouter
{
    /**
     * @var array<string, array{handler: Closure, module: string}>
     */
    private array $handlers = [];

    /**
     * @var array{handler: Closure, module: string}|null
     */
    private ?array $fallback = null;

    public function __construct(
        private readonly ?UsageTracker $usageTracker = null,
        private readonly ?FeatureRegistry $features = null,
        private readonly string $featureKey = 'inline_routing'
    ) {
    }

    /**
     * @param callable(InlineQueryContext, string): void $handler
     */
    public function route(
        string $command,
        callable $handler,
        ?string $module = null
    ): self {
        $command = mb_strtolower(
            trim($command)
        );

        if ($command === '') {
            return $this;
        }

        $this->handlers[$command] = [
            'handler' => Closure::fromCallable($handler),
            'module' => $module
                ?? $this->inferModule($handler),
        ];

        return $this;
    }

    /**
     * @param callable(InlineQueryContext, string): void $handler
     */
    public function fallback(
        callable $handler,
        ?string $module = null
    ): self {
        $this->fallback = [
            'handler' => Closure::fromCallable($handler),
            'module' => $module
                ?? $this->inferModule($handler),
        ];

        return $this;
    }

    public function dispatch(
        InlineQueryContext $context
    ): bool {
        if (
            $this->features !== null
            && !$this->features->isEnabled(
                $this->featureKey,
                $context->userId()
            )
        ) {
            $context->ensureAnswered([
                'cache_time' => 1,
                'is_personal' => true,
            ]);

            return false;
        }

        $query = $context->queryText();
        $parts = preg_split(
            '/\s+/u',
            $query,
            2,
            PREG_SPLIT_NO_EMPTY
        );

        $command = is_array($parts)
            && isset($parts[0])
            ? mb_strtolower($parts[0])
            : '';

        $arguments = is_array($parts)
            && isset($parts[1])
            ? trim($parts[1])
            : '';

        $entry = $this->handlers[$command]
            ?? null;

        if ($entry !== null) {
            $this->invoke(
                $entry,
                $context,
                $arguments,
                $command !== ''
                    ? $command
                    : 'empty'
            );

            return true;
        }

        if ($this->fallback !== null) {
            $this->invoke(
                $this->fallback,
                $context,
                $query,
                $command !== ''
                    ? $command
                    : 'empty'
            );

            return true;
        }

        $context->ensureAnswered([
            'cache_time' => 1,
            'is_personal' => true,
        ]);

        return false;
    }

    /**
     * @param array{handler: Closure, module: string} $entry
     */
    private function invoke(
        array $entry,
        InlineQueryContext $context,
        string $arguments,
        string $action
    ): void {
        $span = $this->usageTracker?->start(
            module: $entry['module'],
            action: $action,
            inputKind: 'inline_query',
            context: $context->updateContext,
            userId: $context->userId(),
            chatType: $context->chatType()
        );

        try {
            ($entry['handler'])($context, $arguments);
            $context->ensureAnswered([
                'cache_time' => 1,
                'is_personal' => true,
            ]);
            $span?->success();
        } catch (Throwable $exception) {
            $span?->failure($exception);

            try {
                $context->ensureAnswered([
                    'cache_time' => 1,
                    'is_personal' => true,
                ]);
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    private function inferModule(callable $handler): string
    {
        try {
            $reflection = new ReflectionFunction(
                Closure::fromCallable($handler)
            );

            $object = $reflection->getClosureThis();

            if ($object !== null) {
                return $this->moduleName(
                    $object::class
                );
            }

            $scope = $reflection->getClosureScopeClass();

            if ($scope !== null) {
                return $this->moduleName(
                    $scope->getName()
                );
            }
        } catch (Throwable) {
        }

        return 'inline';
    }

    private function moduleName(string $class): string
    {
        $separator = strrpos($class, '\\');
        $name = $separator === false
            ? $class
            : substr($class, $separator + 1);

        $name = preg_replace(
            '/Module$/',
            '',
            $name
        ) ?? $name;

        return mb_strtolower(
            preg_replace(
                '/(?<!^)[A-Z]/',
                '_$0',
                $name
            ) ?? $name
        );
    }
}
