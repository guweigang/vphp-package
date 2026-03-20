<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Command;

use InvalidArgumentException;

final class UpdateCommand extends AbstractCommand
{
    public static function fromCommand(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Command $command): self
    {
        if ($command->eventName() !== 'update') {
            throw new InvalidArgumentException('UpdateCommand requires event=update');
        }

        return new self($command);
    }

    public function isTokenTarget(): bool
    {
        return $this->targetType() === 'token';
    }

    public function isMessageIdTarget(): bool
    {
        return $this->targetType() === 'message_id';
    }

    public function isInteractive(): bool
    {
        return $this->messageType() === 'interactive';
    }
}
