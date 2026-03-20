<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Event;

use InvalidArgumentException;

final class CardActionEvent extends AbstractEvent
{
    public static function fromEvent(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Event $event): self
    {
        if ($event->eventKind() !== 'action') {
            throw new InvalidArgumentException('CardActionEvent requires event_kind=action');
        }
        if ($event->openMessageId() === '') {
            throw new InvalidArgumentException('CardActionEvent requires open_message_id');
        }

        return new self($event);
    }

    public function actionTag(): string
    {
        return $this->event->actionTag();
    }

    /**
     * @return mixed
     */
    public function actionValue(): mixed
    {
        return $this->event->actionValue();
    }

    public function token(): string
    {
        return $this->event->token();
    }

    public function openMessageId(): string
    {
        return $this->event->openMessageId();
    }
}
