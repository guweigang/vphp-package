<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Content;

final class PostContent implements \JsonSerializable
{
    private string $locale;
    private string $title;

    /**
     * @var array<int,array<int,array<string,string>>>
     */
    private array $lines = [];

    private function __construct(string $title, string $locale = 'zh_cn')
    {
        $this->locale = $locale;
        $this->title = $title;
    }

    public static function create(string $title, string $locale = 'zh_cn'): self
    {
        return new self($title, $locale);
    }

    public function textLine(string $text): self
    {
        $this->lines[] = [
            [
                'tag' => 'text',
                'text' => $text,
            ],
        ];
        return $this;
    }

    /**
     * @param array<int,array<string,string>> $segments
     */
    public function line(array $segments): self
    {
        $this->lines[] = $segments;
        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            $this->locale => [
                'title' => $this->title,
                'content' => $this->lines,
            ],
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
