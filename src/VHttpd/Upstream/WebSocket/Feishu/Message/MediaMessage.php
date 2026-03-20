<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

use InvalidArgumentException;

final class MediaMessage extends AbstractMessage
{
    public static function fromMessage(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message): self
    {
        if ($message->messageType() !== 'media') {
            throw new InvalidArgumentException('MediaMessage requires message_type=media');
        }

        return new self($message);
    }

    public function fileKey(): string
    {
        return $this->message->fileKey();
    }

    public function imageKey(): string
    {
        return $this->message->imageKey();
    }

    public function fileName(): string
    {
        return $this->message->fileName();
    }

    public function duration(): string
    {
        return $this->message->duration();
    }
}
