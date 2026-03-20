<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Content;

final class CardActionBlock implements \JsonSerializable
{
    /**
     * @var CardButton[]
     */
    private array $buttons;

    private function __construct(CardButton ...$buttons)
    {
        $this->buttons = $buttons;
    }

    public static function create(CardButton ...$buttons): self
    {
        return new self(...$buttons);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'tag' => 'action',
            'actions' => array_map(
                static fn (CardButton $button): array => $button->toArray(),
                $this->buttons,
            ),
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
