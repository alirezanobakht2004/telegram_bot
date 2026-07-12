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
     * @var list<Closure(MessageContext, string): bool>
     */
    private array $fallbackTextHandlers = [];

    /**
     * @var Closure(MessageContext, string): void|null
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
     * این Handlerها فقط وقتی اجرا می‌شوند که متن، دستور یا دکمه
     * ثبت‌شده نباشد. اولین Handler که true برگرداند، متن را مصرف می‌کند.
     *
     * @param callable(MessageContext, string): bool $handler
     */
    public function fallbackText(callable $handler): self
    {
        $this->fallbackTextHandlers[] = Closure::fromCallable(
            $handler
        );

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

        $normalizedText = $this->normalizeText(
            $context->text
        );

        $handler = $this->textHandlers[
            $normalizedText
        ] ?? null;

        if ($handler !== null) {
            $handler($context, '');

            return true;
        }

        foreach ($this->fallbackTextHandlers as $fallbackHandler) {
            if ($fallbackHandler($context, $context->text)) {
                return true;
            }
        }

        return false;
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
