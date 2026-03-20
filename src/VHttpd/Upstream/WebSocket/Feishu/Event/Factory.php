<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Event;

final class Factory
{
    public static function fromEvent(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Event $event): ?AbstractEvent
    {
        if ($event->isCardAction()) {
            return CardActionEvent::fromEvent($event);
        }

        return null;
    }
}
