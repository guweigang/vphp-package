<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Command;

use InvalidArgumentException;

final class SendCommand extends AbstractCommand
{
    public static function fromCommand(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Command $command): self
    {
        if ($command->eventName() !== 'send') {
            throw new InvalidArgumentException('SendCommand requires event=send');
        }

        return new self($command);
    }

    public function isInteractive(): bool
    {
        return $this->messageType() === 'interactive';
    }

    public function isPost(): bool
    {
        return $this->messageType() === 'post';
    }

    public function imageKey(): string
    {
        return trim((string) ($this->contentFields()['image_key'] ?? ''));
    }

    public function fileKey(): string
    {
        return trim((string) ($this->contentFields()['file_key'] ?? ''));
    }

    public function fileName(): string
    {
        return trim((string) ($this->contentFields()['file_name'] ?? ''));
    }

    public function duration(): string
    {
        return trim((string) ($this->contentFields()['duration'] ?? ''));
    }
}
