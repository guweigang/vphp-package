<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Message;

final class Factory
{
    public static function fromMessage(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Message $message): ?AbstractMessage
    {
        return match ($message->messageType()) {
            'text' => TextMessage::fromMessage($message),
            'image' => ImageMessage::fromMessage($message),
            'post' => PostMessage::fromMessage($message),
            'file' => FileMessage::fromMessage($message),
            'audio' => AudioMessage::fromMessage($message),
            'media' => MediaMessage::fromMessage($message),
            'sticker' => StickerMessage::fromMessage($message),
            default => null,
        };
    }
}
