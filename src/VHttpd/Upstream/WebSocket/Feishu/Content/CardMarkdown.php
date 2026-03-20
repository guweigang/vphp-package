<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Content;

final class CardMarkdown implements \JsonSerializable
{
    private function __construct(
        private readonly string $content,
    ) {
    }

    public static function create(string $content): self
    {
        return new self($content);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'tag' => 'markdown',
            'content' => $this->content,
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
