<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Content;

final class InteractiveCard implements \JsonSerializable
{
    /**
     * @var array<string,mixed>
     */
    private array $card;

    private function __construct(string $title = '')
    {
        $this->card = [
            'config' => [
                'wide_screen_mode' => true,
            ],
            'header' => CardHeader::create($title)->toArray(),
            'elements' => [],
        ];
    }

    public static function create(string $title = ''): self
    {
        return new self($title);
    }

    public function wideScreen(bool $enabled = true): self
    {
        $this->card['config']['wide_screen_mode'] = $enabled;
        return $this;
    }

    public function title(string|PlainText $title): self
    {
        $this->card['header'] = CardHeader::create($title)->toArray();
        return $this;
    }

    public function header(CardHeader $header): self
    {
        $this->card['header'] = $header->toArray();
        return $this;
    }

    public function markdown(string|CardMarkdown $content): self
    {
        $resolvedContent = $content instanceof CardMarkdown ? $content : CardMarkdown::create($content);
        return $this->element($resolvedContent);
    }

    public function element(\JsonSerializable $element): self
    {
        $this->card['elements'][] = $element->jsonSerialize();
        return $this;
    }

    public function button(
        string $label,
        array|CardActionValue $value = [],
        string $type = 'default',
    ): self
    {
        $resolvedValue = $value instanceof CardActionValue ? $value : CardActionValue::fromArray($value);
        return $this->action(CardButton::create($label, $resolvedValue, $type));
    }

    public function action(CardButton ...$buttons): self
    {
        return $this->element(CardActionBlock::create(...$buttons));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->card;
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
