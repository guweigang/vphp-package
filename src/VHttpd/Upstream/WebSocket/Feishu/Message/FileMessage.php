<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

use InvalidArgumentException;

final class FileMessage extends AbstractMessage
{
    public static function fromMessage(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message): self
    {
        if ($message->messageType() !== 'file') {
            throw new InvalidArgumentException('FileMessage requires message_type=file');
        }

        return new self($message);
    }

    public function fileKey(): string
    {
        return $this->message->fileKey();
    }

    public function fileName(): string
    {
        return $this->message->fileName();
    }
}
