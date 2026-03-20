<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Content;

final class CardActionValue implements \JsonSerializable
{
    /**
     * @var array<string,mixed>
     */
    private array $value;

    /**
     * @param array<string,mixed> $value
     */
    private function __construct(array $value)
    {
        $this->value = $value;
    }

    public static function action(string $action, array $extra = []): self
    {
        return new self(['action' => $action] + $extra);
    }

    /**
     * @param array<string,mixed> $value
     */
    public static function fromArray(array $value): self
    {
        return new self($value);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->value;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function toDebugArray(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toDebugArray();
    }
}
