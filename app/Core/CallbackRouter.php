<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use Closure;
use ReflectionFunction;
use Throwable;

final class CallbackRouter
{
    /**
     * @var list<array{
     *     prefix: string,
     *     handler: Closure,
     *     module: string
     * }>
     */
    private array $handlers = [];

    /**
     * @var array{handler: Closure, module: string}|null
     */
    private ?array $fallback = null;

    public function __construct(
        private readonly ?UsageTracker $usageTracker = null,
        private readonly ?FeatureRegistry $features = null,
        private readonly string $featureKey = 'callback_routing'
    ) {
    }

    /**
     * @param callable(CallbackQueryContext, string): void $handler
     */
    public function on(
        string $prefix,
        callable $handler,
        ?string $module = null
    ): self {
        $prefix = trim($prefix);

        if ($prefix === '') {
            return $this;
        }

        $this->handlers[] = [
            'prefix' => $prefix,
            'handler' => Closure::fromCallable($handler),
            'module' => $module
                ?? $this->inferModule($handler),
        ];

        usort(
            $this->handlers,
            static fn (array $left, array $right): int =>
                strlen($right['prefix'])
                <=> strlen($left['prefix'])
        );

        return $this;
    }

    /**
     * @param callable(CallbackQueryContext, string): void $handler
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
        CallbackQueryContext $context
    ): bool {
        if (
            $this->features !== null
            && !$this->features->isEnabled(
                $this->featureKey,
                $context->userId()
            )
        ) {
            $context->ensureAnswered();

            return false;
        }

        $data = $context->data();

        foreach ($this->handlers as $entry) {
            if (!str_starts_with(
                $data,
                $entry['prefix']
            )) {
                continue;
            }

            $suffix = substr(
                $data,
                strlen($entry['prefix'])
            );

            $this->invoke(
                $entry,
                $context,
                $suffix
            );

            return true;
        }

        if ($this->fallback !== null) {
            $this->invoke(
                $this->fallback,
                $context,
                $data
            );

            return true;
        }

        $context->ensureAnswered();

        return false;
    }

    /**
     * @param array{handler: Closure, module: string} $entry
     */
    private function invoke(
        array $entry,
        CallbackQueryContext $context,
        string $value
    ): void {
        $span = $this->usageTracker?->start(
            module: $entry['module'],
            action: mb_substr(
                $context->data(),
                0,
                120
            ),
            inputKind: 'callback',
            context: $context->updateContext,
            userId: $context->userId(),
            chatId: $context->chatId(),
            chatType: $context->updateContext->chatType()
        );

        try {
            ($entry['handler'])($context, $value);
            $context->ensureAnswered();
            $span?->success();
        } catch (Throwable $exception) {
            $span?->failure($exception);

            try {
                $context->ensureAnswered();
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

        return 'callback';
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
