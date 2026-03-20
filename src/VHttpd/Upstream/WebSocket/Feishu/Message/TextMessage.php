<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

use InvalidArgumentException;

final class TextMessage extends AbstractMessage
{
    public static function fromMessage(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message): self
    {
        if ($message->messageType() !== 'text') {
            throw new InvalidArgumentException('TextMessage requires message_type=text');
        }

        return new self($message);
    }

    public function text(): string
    {
        return $this->message->text();
    }
}
