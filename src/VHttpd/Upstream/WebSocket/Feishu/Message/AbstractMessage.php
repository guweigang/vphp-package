<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

abstract class AbstractMessage implements \JsonSerializable
{
    public function __construct(
        protected readonly \VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message,
    ) {
    }

    public function message(): \VPhp\VHttpd\Upstream\WebSocket\Feishu\Message
    {
        return $this->message;
    }

    public function provider(): string
    {
        return $this->message->provider();
    }

    public function instance(): string
    {
        return $this->message->instance();
    }

    public function targetType(): string
    {
        return $this->message->targetType();
    }

    public function target(): string
    {
        return $this->message->target();
    }

    public function messageId(): string
    {
        return $this->message->messageId();
    }

    public function messageType(): string
    {
        return $this->message->messageType();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->message->toArray();
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
