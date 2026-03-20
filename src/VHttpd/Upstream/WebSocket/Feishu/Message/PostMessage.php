<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

use InvalidArgumentException;

final class PostMessage extends AbstractMessage
{
    public static function fromMessage(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message): self
    {
        if ($message->messageType() !== 'post') {
            throw new InvalidArgumentException('PostMessage requires message_type=post');
        }

        return new self($message);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function postContent(): ?array
    {
        return $this->message->postContent();
    }
}
