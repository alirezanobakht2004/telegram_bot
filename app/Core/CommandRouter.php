<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use Closure;
use ReflectionFunction;
use Throwable;

final class CommandRouter
{
    /**
     * @var array<string, array{handler: Closure, module: string}>
     */
    private array $commandHandlers = [];

    /**
     * @var array<string, array{handler: Closure, module: string, label: string}>
     */
    private array $textHandlers = [];

    /**
     * @var list<array{handler: Closure, module: string}>
     */
    private array $fallbackTextHandlers = [];

    /**
     * @var list<array{handler: Closure, module: string}>
     */
    private array $fallbackCommandHandlers = [];

    /**
     * @var array{handler: Closure, module: string}|null
     */
    private ?array $unknownCommandHandler = null;

    private readonly string $botUsername;

    public function __construct(
        string $botUsername,
        private readonly ?UsageTracker $usageTracker = null,
        private readonly ?CommandHistory $history = null
    ) {
        $this->botUsername = ltrim(
            mb_strtolower(trim($botUsername)),
            '@'
        );
    }

    /**
     * @param callable(MessageContext, string): void $handler
     */
    public function command(
        string $command,
        callable $handler,
        ?string $module = null
    ): self {
        $command = ltrim(
            mb_strtolower(trim($command)),
            '/'
        );

        if ($command === '') {
            return $this;
        }

        $this->commandHandlers[$command] = [
            'handler' => Closure::fromCallable($handler),
            'module' => $module
                ?? $this->inferModule($handler),
        ];

        return $this;
    }

    /**
     * @param callable(MessageContext, string): void $handler
     */
    public function text(
        string $text,
        callable $handler,
        ?string $module = null
    ): self {
        $normalized = $this->normalizeText($text);

        if ($normalized === '') {
            return $this;
        }

        $this->textHandlers[$normalized] = [
            'handler' => Closure::fromCallable($handler),
            'module' => $module
                ?? $this->inferModule($handler),
            'label' => trim($text),
        ];

        return $this;
    }

    /**
     * @param callable(MessageContext, string): bool $handler
     */
    public function fallbackText(
        callable $handler,
        ?string $module = null
    ): self {
        $this->fallbackTextHandlers[] = [
            'handler' => Closure::fromCallable($handler),
            'module' => $module
                ?? $this->inferModule($handler),
        ];

        return $this;
    }


    /**
     * Dynamic commands such as per-user shortcuts are checked after
     * registered commands and before the generic unknown-command handler.
     *
     * @param callable(MessageContext, string, string): bool $handler
     */
    public function fallbackCommand(
        callable $handler,
        ?string $module = null
    ): self {
        $this->fallbackCommandHandlers[] = [
            'handler' => Closure::fromCallable($handler),
            'module' => $module
                ?? $this->inferModule($handler),
        ];

        return $this;
    }

    /**
     * @param callable(MessageContext, string): void $handler
     */
    public function unknownCommand(
        callable $handler,
        ?string $module = null
    ): self {
        $this->unknownCommandHandler = [
            'handler' => Closure::fromCallable($handler),
            'module' => $module
                ?? $this->inferModule($handler),
        ];

        return $this;
    }

    public function dispatch(MessageContext $context): bool
    {
        $command = $this->parseCommand($context->text);

        if ($command !== null) {
            if (
                $command['mention'] !== null
                && $command['mention'] !== $this->botUsername
            ) {
                return false;
            }

            $entry = $this->commandHandlers[
                $command['name']
            ] ?? null;

            if ($entry !== null) {
                $this->invokeCommand(
                    $entry,
                    $context,
                    $command['name'],
                    $command['arguments'],
                    'command'
                );

                return true;
            }

            foreach ($this->fallbackCommandHandlers as $fallback) {
                $span = $this->usageTracker?->start(
                    module: $fallback['module'],
                    action: 'fallback_command.' . $command['name'],
                    inputKind: 'command',
                    context: $context->updateContext,
                    userId: $context->userId,
                    chatId: $context->chatId,
                    chatType: $context->chatType
                );

                $startedAt = hrtime(true);

                try {
                    $consumed = ($fallback['handler'])(
                        $context,
                        $command['name'],
                        $command['arguments']
                    );

                    $duration = max(
                        0.0,
                        (hrtime(true) - $startedAt) / 1_000_000
                    );

                    if (!$consumed) {
                        $span?->discard();
                        continue;
                    }

                    $span?->success();

                    $this->history?->record(
                        module: $fallback['module'],
                        command: $command['name'],
                        source: 'shortcut',
                        arguments: $command['arguments'],
                        success: true,
                        durationMs: $duration,
                        messageContext: $context
                    );

                    return true;
                } catch (Throwable $exception) {
                    $duration = max(
                        0.0,
                        (hrtime(true) - $startedAt) / 1_000_000
                    );

                    $span?->failure($exception);

                    $this->history?->record(
                        module: $fallback['module'],
                        command: $command['name'],
                        source: 'shortcut',
                        arguments: $command['arguments'],
                        success: false,
                        durationMs: $duration,
                        messageContext: $context
                    );

                    throw $exception;
                }
            }

            if ($this->unknownCommandHandler !== null) {
                $this->invokeCommand(
                    $this->unknownCommandHandler,
                    $context,
                    $command['name'],
                    $command['name'],
                    'unknown_command'
                );

                return true;
            }

            return false;
        }

        $normalizedText = $this->normalizeText(
            $context->text
        );

        $entry = $this->textHandlers[$normalizedText]
            ?? null;

        if ($entry !== null) {
            $this->invokeCommand(
                $entry,
                $context,
                $entry['label'],
                null,
                'button'
            );

            return true;
        }

        foreach ($this->fallbackTextHandlers as $fallback) {
            $span = $this->usageTracker?->start(
                module: $fallback['module'],
                action: 'fallback_text',
                inputKind: 'text',
                context: $context->updateContext,
                userId: $context->userId,
                chatId: $context->chatId,
                chatType: $context->chatType
            );

            $startedAt = hrtime(true);

            try {
                $consumed = ($fallback['handler'])(
                    $context,
                    $context->text
                );

                $duration = max(
                    0.0,
                    (hrtime(true) - $startedAt) / 1_000_000
                );

                if (!$consumed) {
                    $span?->discard();
                    continue;
                }

                $span?->success();

                $this->history?->record(
                    module: $fallback['module'],
                    command: 'fallback_text',
                    source: 'text',
                    arguments: null,
                    success: true,
                    durationMs: $duration,
                    messageContext: $context
                );

                return true;
            } catch (Throwable $exception) {
                $duration = max(
                    0.0,
                    (hrtime(true) - $startedAt) / 1_000_000
                );

                $span?->failure($exception);

                $this->history?->record(
                    module: $fallback['module'],
                    command: 'fallback_text',
                    source: 'text',
                    arguments: null,
                    success: false,
                    durationMs: $duration,
                    messageContext: $context
                );

                throw $exception;
            }
        }

        return false;
    }

    /**
     * @param array{handler: Closure, module: string} $entry
     */
    private function invokeCommand(
        array $entry,
        MessageContext $context,
        string $action,
        ?string $arguments,
        string $source
    ): void {
        $span = $this->usageTracker?->start(
            module: $entry['module'],
            action: $this->normalizeAction($action),
            inputKind: $source,
            context: $context->updateContext,
            userId: $context->userId,
            chatId: $context->chatId,
            chatType: $context->chatType
        );

        $startedAt = hrtime(true);

        try {
            ($entry['handler'])(
                $context,
                $arguments ?? ''
            );

            $duration = max(
                0.0,
                (hrtime(true) - $startedAt) / 1_000_000
            );

            $span?->success();

            $this->history?->record(
                module: $entry['module'],
                command: $action,
                source: $source,
                arguments: $arguments,
                success: true,
                durationMs: $duration,
                messageContext: $context
            );
        } catch (Throwable $exception) {
            $duration = max(
                0.0,
                (hrtime(true) - $startedAt) / 1_000_000
            );

            $span?->failure($exception);

            $this->history?->record(
                module: $entry['module'],
                command: $action,
                source: $source,
                arguments: $arguments,
                success: false,
                durationMs: $duration,
                messageContext: $context
            );

            throw $exception;
        }
    }

    /**
     * @return array{
     *     name: string,
     *     mention: ?string,
     *     arguments: string
     * }|null
     */
    private function parseCommand(string $text): ?array
    {
        $matched = preg_match(
            '/^\/([a-z0-9_]+)(?:@([a-z0-9_]+))?(?:\s+(.*))?$/iu',
            trim($text),
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        return [
            'name' => mb_strtolower($matches[1]),
            'mention' => isset($matches[2])
                && $matches[2] !== ''
                ? mb_strtolower($matches[2])
                : null,
            'arguments' => isset($matches[3])
                ? trim($matches[3])
                : '',
        ];
    }

    private function inferModule(callable $handler): string
    {
        try {
            $reflection = new ReflectionFunction(
                Closure::fromCallable($handler)
            );

            $object = $reflection->getClosureThis();

            if ($object !== null) {
                return $this->moduleName($object::class);
            }

            $scope = $reflection->getClosureScopeClass();

            if ($scope !== null) {
                return $this->moduleName($scope->getName());
            }
        } catch (Throwable) {
        }

        return 'core';
    }

    private function moduleName(string $class): string
    {
        $separator = strrpos($class, '\\');
        $name = $separator === false
            ? $class
            : substr($class, $separator + 1);

        $name = preg_replace('/Module$/', '', $name)
            ?? $name;

        return mb_strtolower(
            preg_replace(
                '/(?<!^)[A-Z]/',
                '_$0',
                $name
            ) ?? $name
        );
    }

    private function normalizeAction(string $action): string
    {
        $action = mb_strtolower(trim($action));
        $action = preg_replace(
            '/[^\p{L}\p{N}_.:-]+/u',
            '_',
            $action
        ) ?? $action;

        return mb_substr(
            $action !== '' ? $action : 'unknown',
            0,
            120
        );
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
