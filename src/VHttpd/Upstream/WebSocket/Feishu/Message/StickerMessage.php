<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

use InvalidArgumentException;

final class StickerMessage extends AbstractMessage
{
    public static function fromMessage(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message): self
    {
        if ($message->messageType() !== 'sticker') {
            throw new InvalidArgumentException('StickerMessage requires message_type=sticker');
        }

        return new self($message);
    }

    public function fileKey(): string
    {
        return $this->message->fileKey();
    }
}
