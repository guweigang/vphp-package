<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu;

use InvalidArgumentException;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Event\CardActionEvent;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Event\Factory as EventFactory;

final class Event implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $data
     */
    private function __construct(
        private readonly array $data,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $eventType = trim((string) ($data['event_type'] ?? ''));
        if ($eventType === '') {
            throw new InvalidArgumentException('Feishu event_type is required');
        }

        $normalized = $data;
        $normalized['provider'] = trim((string) ($data['provider'] ?? 'feishu')) ?: 'feishu';
        $normalized['instance'] = trim((string) ($data['instance'] ?? 'main')) ?: 'main';
        $normalized['event_type'] = $eventType;
        $normalized['event_kind'] = trim((string) ($data['event_kind'] ?? 'event')) ?: 'event';
        $normalized['target_type'] = (string) ($data['target_type'] ?? '');
        $normalized['target'] = (string) ($data['target'] ?? '');

        return new self($normalized);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
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

    public function provider(): string
    {
        return (string) ($this->data['provider'] ?? 'feishu');
    }

    public function instance(): string
    {
        return (string) ($this->data['instance'] ?? 'main');
    }

    public function targetType(): string
    {
        return (string) ($this->data['target_type'] ?? '');
    }

    public function target(): string
    {
        return (string) ($this->data['target'] ?? '');
    }

    public function eventKind(): string
    {
        return (string) ($this->data['event_kind'] ?? '');
    }

    public function eventType(): string
    {
        return (string) ($this->data['event_type'] ?? '');
    }

    public function eventId(): string
    {
        return (string) ($this->data['event_id'] ?? '');
    }

    public function chatType(): string
    {
        return (string) ($this->data['chat_type'] ?? '');
    }

    public function rootId(): string
    {
        return (string) ($this->data['root_id'] ?? '');
    }

    public function parentId(): string
    {
        return (string) ($this->data['parent_id'] ?? '');
    }

    public function createTime(): string
    {
        return (string) ($this->data['create_time'] ?? '');
    }

    public function messageId(): string
    {
        return (string) ($this->data['message_id'] ?? '');
    }

    public function messageType(): string
    {
        return (string) ($this->data['message_type'] ?? '');
    }

    public function actionTag(): string
    {
        return (string) ($this->data['action_tag'] ?? '');
    }

    /**
     * @return mixed
     */
    public function actionValue(): mixed
    {
        return $this->data['action_value'] ?? null;
    }

    public function openMessageId(): string
    {
        return (string) ($this->data['open_message_id'] ?? '');
    }

    public function token(): string
    {
        return (string) ($this->data['token'] ?? '');
    }

    public function senderId(): string
    {
        return (string) ($this->data['sender_id'] ?? '');
    }

    public function senderIdType(): string
    {
        return (string) ($this->data['sender_id_type'] ?? '');
    }

    public function senderTenantKey(): string
    {
        return (string) ($this->data['sender_tenant_key'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return is_array($this->data['metadata'] ?? null) ? $this->data['metadata'] : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function content(): ?array
    {
        return is_array($this->data['content'] ?? null) ? $this->data['content'] : null;
    }

    public function text(): string
    {
        $content = $this->content() ?? [];
        return trim((string) ($content['text'] ?? ''));
    }

    public function imageKey(): string
    {
        $content = $this->content() ?? [];
        return (string) ($content['image_key'] ?? '');
    }

    public function fileKey(): string
    {
        $content = $this->content() ?? [];
        return (string) ($content['file_key'] ?? '');
    }

    public function fileName(): string
    {
        $content = $this->content() ?? [];
        return (string) ($content['file_name'] ?? '');
    }

    public function duration(): string
    {
        $content = $this->content() ?? [];
        return (string) ($content['duration'] ?? '');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function postContent(): ?array
    {
        $content = $this->content() ?? [];
        return is_array($content['post'] ?? null) ? $content['post'] : null;
    }

    public function isCardAction(): bool
    {
        return $this->eventKind() === 'action' && $this->openMessageId() !== '';
    }

    public function asCardAction(): ?CardActionEvent
    {
        if (!$this->isCardAction()) {
            return null;
        }

        return EventFactory::fromEvent($this);
    }
}
