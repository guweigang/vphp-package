<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Content;

final class CardButton implements \JsonSerializable
{
    private string $type;
    private CardActionValue $value;
    private PlainText $text;

    private function __construct(string|PlainText $label, CardActionValue $value, string $type = 'default')
    {
        $this->text = $label instanceof PlainText ? $label : PlainText::create($label);
        $this->value = $value;
        $this->type = $type;
    }

    public static function create(string|PlainText $label, CardActionValue $value, string $type = 'default'): self
    {
        return new self($label, $value, $type);
    }

    public static function primary(string|PlainText $label, CardActionValue $value): self
    {
        return new self($label, $value, 'primary');
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'tag' => 'button',
            'text' => $this->text->toArray(),
            'type' => $this->type,
            'value' => $this->value->toArray(),
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
