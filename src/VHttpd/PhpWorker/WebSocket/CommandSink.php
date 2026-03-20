<?php

declare(strict_types=1);

namespace VPhp\VHttpd\PhpWorker\WebSocket;

interface CommandSink
{
    public function id(): string;

    public function accepted(): bool;

    public function closed(): bool;

    public function accept(): void;

    public function send(string $data, string $opcode = 'text'): void;

    public function join(string $room): void;

    public function leave(string $room): void;

    public function broadcast(
        string $room,
        string $data,
        string $opcode = 'text',
        string $exceptId = '',
    ): void;

    public function sendTo(string $targetId, string $data, string $opcode = 'text'): void;

    public function setMeta(string $key, string $value): void;

    public function clearMeta(string $key): void;

    public function setPresence(string $value): void;

    public function close(int $code = 1000, string $reason = '', int $status = 0): void;
}
