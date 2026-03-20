<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Content;

final class CardHeader implements \JsonSerializable
{
    private function __construct(
        private readonly PlainText $title,
    ) {
    }

    public static function create(string|PlainText $title): self
    {
        $resolvedTitle = $title instanceof PlainText ? $title : PlainText::create($title);
        return new self($resolvedTitle);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title->toArray(),
        ];
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
