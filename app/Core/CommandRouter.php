<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use Closure;

final class CommandRouter
{
    /**
     * @var array<string, Closure(MessageContext, string): void>
     */
    private array $commandHandlers = [];

    /**
     * @var array<string, Closure(MessageContext, string): void>
     */
    private array $textHandlers = [];

    /**
     * @var Closure(MessageContext, string)|null
     */
    private ?Closure $unknownCommandHandler = null;

    private readonly string $botUsername;

    public function __construct(string $botUsername)
    {
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
        callable $handler
    ): self {
        $command = ltrim(
            mb_strtolower(trim($command)),
            '/'
        );

        $this->commandHandlers[$command] = Closure::fromCallable(
            $handler
        );

        return $this;
    }

    /**
     * @param callable(MessageContext, string): void $handler
     */
    public function text(
        string $text,
        callable $handler
    ): self {
        $this->textHandlers[$this->normalizeText($text)] =
            Closure::fromCallable($handler);

        return $this;
    }

    /**
     * @param callable(MessageContext, string): void $handler
     */
    public function unknownCommand(callable $handler): self
    {
        $this->unknownCommandHandler = Closure::fromCallable(
            $handler
        );

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

            $handler = $this->commandHandlers[
                $command['name']
            ] ?? null;

            if ($handler !== null) {
                $handler(
                    $context,
                    $command['arguments']
                );

                return true;
            }

            if ($this->unknownCommandHandler !== null) {
                ($this->unknownCommandHandler)(
                    $context,
                    $command['name']
                );

                return true;
            }

            return false;
        }

        $handler = $this->textHandlers[
            $this->normalizeText($context->text)
        ] ?? null;

        if ($handler === null) {
            return false;
        }

        $handler($context, '');

        return true;
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
                ? mb_strtolower($matches[2])
                : null,
            'arguments' => isset($matches[3])
                ? trim($matches[3])
                : '',
        ];
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}