<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Event;

abstract class AbstractEvent implements \JsonSerializable
{
    public function __construct(
        protected readonly \VPhp\VHttpd\Upstream\WebSocket\Feishu\Event $event,
    ) {
    }

    public function event(): \VPhp\VHttpd\Upstream\WebSocket\Feishu\Event
    {
        return $this->event;
    }

    public function provider(): string
    {
        return $this->event->provider();
    }

    public function instance(): string
    {
        return $this->event->instance();
    }

    public function eventType(): string
    {
        return $this->event->eventType();
    }

    public function eventKind(): string
    {
        return $this->event->eventKind();
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
}
