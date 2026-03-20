<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

use InvalidArgumentException;

final class ImageMessage extends AbstractMessage
{
    public static function fromMessage(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message): self
    {
        if ($message->messageType() !== 'image') {
            throw new InvalidArgumentException('ImageMessage requires message_type=image');
        }

        return new self($message);
    }

    public function imageKey(): string
    {
        return $this->message->imageKey();
    }
}
