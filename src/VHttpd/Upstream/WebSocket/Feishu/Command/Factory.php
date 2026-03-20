<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Command;

final class Factory
{
    public static function fromCommand(\VPhp\VHttpd\Upstream\WebSocket\Feishu\Command $command): ?AbstractCommand
    {
        return match ($command->eventName()) {
            'send' => SendCommand::fromCommand($command),
            'update' => UpdateCommand::fromCommand($command),
            default => null,
        };
    }
}
