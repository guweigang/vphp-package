<?php

declare(strict_types=1);

namespace VPhp\VHttpd\PhpWorker\WebSocket;

final class CommandBuffer implements CommandSink
{
    /** @var list<array<string,mixed>> */
    private array $commands = [];
    private bool $accepted = false;
    private bool $closed = false;

    public function __construct(
        private string $id,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function accepted(): bool
    {
        return $this->accepted;
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    /** @return list<array<string,mixed>> */
    public function commands(): array
    {
        return $this->commands;
    }

    public function accept(): void
    {
        if ($this->closed || $this->accepted) {
            return;
        }
        $this->accepted = true;
    }

    public function send(string $data, string $opcode = 'text'): void
    {
        if ($this->closed) {
            return;
        }
        $this->commands[] = [
            'event' => 'send',
            'id' => $this->id,
            'opcode' => $opcode,
            'data' => $data,
        ];
    }

    public function join(string $room): void
    {
        if ($this->closed || $room === '') {
            return;
        }
        $this->commands[] = [
            'event' => 'join',
            'id' => $this->id,
            'room' => $room,
        ];
    }

    public function leave(string $room): void
    {
        if ($this->closed || $room === '') {
            return;
        }
        $this->commands[] = [
            'event' => 'leave',
            'id' => $this->id,
            'room' => $room,
        ];
    }

    public function broadcast(
        string $room,
        string $data,
        string $opcode = 'text',
        string $exceptId = '',
    ): void {
        if ($this->closed || $room === '') {
            return;
        }
        $this->commands[] = [
            'event' => 'broadcast',
            'id' => $this->id,
            'room' => $room,
            'data' => $data,
            'opcode' => $opcode,
            'except_id' => $exceptId,
        ];
    }

    public function sendTo(string $targetId, string $data, string $opcode = 'text'): void
    {
        if ($this->closed || $targetId === '') {
            return;
        }
        $this->commands[] = [
            'event' => 'send_to',
            'id' => $this->id,
            'target_id' => $targetId,
            'data' => $data,
            'opcode' => $opcode,
        ];
    }

    public function setMeta(string $key, string $value): void
    {
        if ($this->closed || $key === '') {
            return;
        }
        $this->commands[] = [
            'event' => 'set_meta',
            'id' => $this->id,
            'key' => $key,
            'value' => $value,
        ];
    }

    public function clearMeta(string $key): void
    {
        if ($this->closed || $key === '') {
            return;
        }
        $this->commands[] = [
            'event' => 'clear_meta',
            'id' => $this->id,
            'key' => $key,
        ];
    }

    public function setPresence(string $value): void
    {
        $this->setMeta('presence', $value);
    }

    public function close(int $code = 1000, string $reason = '', int $status = 0): void
    {
        if ($this->closed) {
            return;
        }
        $payload = [
            'event' => 'close',
            'id' => $this->id,
            'code' => $code,
            'reason' => $reason,
        ];
        if ($status > 0) {
            $payload['status'] = $status;
        }
        $this->commands[] = $payload;
        $this->closed = true;
    }
}
