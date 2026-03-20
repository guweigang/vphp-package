<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu;

use InvalidArgumentException;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\AudioMessage;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\Factory as MessageFactory;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\FileMessage;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\ImageMessage;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\PostMessage;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\StickerMessage;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\TextMessage;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\MediaMessage;

final class Message implements \JsonSerializable
{
    private function __construct(
        private readonly Event $event,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $event = Event::fromArray($data);
        if ($event->messageType() === '') {
            throw new InvalidArgumentException('Feishu message_type is required');
        }
        if ($event->messageId() === '') {
            throw new InvalidArgumentException('Feishu message_id is required');
        }

        return new self($event);
    }

    public function event(): Event
    {
        return $this->event;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->event->toArray();
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
        return $this->event->provider();
    }

    public function instance(): string
    {
        return $this->event->instance();
    }

    public function targetType(): string
    {
        return $this->event->targetType();
    }

    public function target(): string
    {
        return $this->event->target();
    }

    public function eventType(): string
    {
        return $this->event->eventType();
    }

    public function chatType(): string
    {
        return $this->event->chatType();
    }

    public function rootId(): string
    {
        return $this->event->rootId();
    }

    public function parentId(): string
    {
        return $this->event->parentId();
    }

    public function createTime(): string
    {
        return $this->event->createTime();
    }

    public function messageId(): string
    {
        return $this->event->messageId();
    }

    public function messageType(): string
    {
        return $this->event->messageType();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function content(): ?array
    {
        return $this->event->content();
    }

    public function text(): string
    {
        return $this->event->text();
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return $this->event->metadata();
    }

    public function senderId(): string
    {
        return $this->event->senderId();
    }

    public function senderIdType(): string
    {
        return $this->event->senderIdType();
    }

    public function senderTenantKey(): string
    {
        return $this->event->senderTenantKey();
    }

    public function imageKey(): string
    {
        return $this->event->imageKey();
    }

    public function fileKey(): string
    {
        return $this->event->fileKey();
    }

    public function fileName(): string
    {
        return $this->event->fileName();
    }

    public function duration(): string
    {
        return $this->event->duration();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function postContent(): ?array
    {
        return $this->event->postContent();
    }

    public function isText(): bool
    {
        return $this->messageType() === 'text';
    }

    public function isImage(): bool
    {
        return $this->messageType() === 'image';
    }

    public function isPost(): bool
    {
        return $this->messageType() === 'post';
    }

    public function isFile(): bool
    {
        return $this->messageType() === 'file';
    }

    public function isAudio(): bool
    {
        return $this->messageType() === 'audio';
    }

    public function isMedia(): bool
    {
        return $this->messageType() === 'media';
    }

    public function isSticker(): bool
    {
        return $this->messageType() === 'sticker';
    }

    public function asText(): ?TextMessage
    {
        if (!$this->isText()) {
            return null;
        }

        return MessageFactory::fromMessage($this);
    }

    public function asImage(): ?ImageMessage
    {
        if (!$this->isImage()) {
            return null;
        }

        return MessageFactory::fromMessage($this);
    }

    public function asPost(): ?PostMessage
    {
        if (!$this->isPost()) {
            return null;
        }

        return MessageFactory::fromMessage($this);
    }

    public function asFile(): ?FileMessage
    {
        if (!$this->isFile()) {
            return null;
        }

        return MessageFactory::fromMessage($this);
    }

    public function asAudio(): ?AudioMessage
    {
        if (!$this->isAudio()) {
            return null;
        }

        return MessageFactory::fromMessage($this);
    }

    public function asMedia(): ?MediaMessage
    {
        if (!$this->isMedia()) {
            return null;
        }

        return MessageFactory::fromMessage($this);
    }

    public function asSticker(): ?StickerMessage
    {
        if (!$this->isSticker()) {
            return null;
        }

        return MessageFactory::fromMessage($this);
    }
}
