<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

use InvalidArgumentException;

final class AudioMessage extends AbstractMessage
{
    public static function fromMessage(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message): self
    {
        if ($message->messageType() !== 'audio') {
            throw new InvalidArgumentException('AudioMessage requires message_type=audio');
        }

        return new self($message);
    }

    public function fileKey(): string
    {
        return $this->message->fileKey();
    }

    public function duration(): string
    {
        return $this->message->duration();
    }
}
