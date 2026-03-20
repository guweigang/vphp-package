<?php

declare(strict_types=1);

namespace VPhp\VHttpd\PhpWorker\WebSocket;

use VPhp\VHttpd\PhpWorker\Client;

final class Connection implements CommandSink
{
    /** @var resource */
    private $conn;
    private bool $accepted = false;
    private bool $closed = false;

    /** @param resource $conn */
    public function __construct(
        $conn,
        private string $id,
    ) {
        $this->conn = $conn;
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

    public function accept(): void
    {
        if ($this->closed || $this->accepted) {
            return;
        }
        $this->write([
            'mode' => 'websocket',
            'event' => 'accept',
            'id' => $this->id,
        ]);
        $this->accepted = true;
    }

    public function send(string $data, string $opcode = 'text'): void
    {
        if ($this->closed) {
            return;
        }
        $this->write([
            'mode' => 'websocket',
            'event' => 'send',
            'id' => $this->id,
            'opcode' => $opcode,
            'data' => $data,
        ]);
    }

    public function join(string $room): void
    {
        if ($this->closed || $room === '') {
            return;
        }
        $this->write([
            'mode' => 'websocket',
            'event' => 'join',
            'id' => $this->id,
            'room' => $room,
        ]);
    }

    public function leave(string $room): void
    {
        if ($this->closed || $room === '') {
            return;
        }
        $this->write([
            'mode' => 'websocket',
            'event' => 'leave',
            'id' => $this->id,
            'room' => $room,
        ]);
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
        $this->write([
            'mode' => 'websocket',
            'event' => 'broadcast',
            'id' => $this->id,
            'room' => $room,
            'data' => $data,
            'opcode' => $opcode,
            'except_id' => $exceptId,
        ]);
    }

    public function sendTo(string $targetId, string $data, string $opcode = 'text'): void
    {
        if ($this->closed || $targetId === '') {
            return;
        }
        $this->write([
            'mode' => 'websocket',
            'event' => 'send_to',
            'id' => $this->id,
            'target_id' => $targetId,
            'data' => $data,
            'opcode' => $opcode,
        ]);
    }

    public function setMeta(string $key, string $value): void
    {
        if ($this->closed || $key === '') {
            return;
        }
        $this->write([
            'mode' => 'websocket',
            'event' => 'set_meta',
            'id' => $this->id,
            'key' => $key,
            'value' => $value,
        ]);
    }

    public function clearMeta(string $key): void
    {
        if ($this->closed || $key === '') {
            return;
        }
        $this->write([
            'mode' => 'websocket',
            'event' => 'clear_meta',
            'id' => $this->id,
            'key' => $key,
        ]);
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
            'mode' => 'websocket',
            'event' => 'close',
            'id' => $this->id,
            'code' => $code,
            'reason' => $reason,
        ];
        if ($status > 0) {
            $payload['status'] = $status;
        }
        $this->write($payload);
        $this->closed = true;
    }

    public function done(): void
    {
        $this->write([
            'mode' => 'websocket',
            'event' => 'done',
            'id' => $this->id,
        ]);
    }

    public function error(string $message, string $errorClass = 'worker_runtime_error'): void
    {
        $this->write([
            'mode' => 'websocket',
            'event' => 'error',
            'id' => $this->id,
            'error_class' => $errorClass,
            'error' => $message,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function write(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('json_encode_failed');
        }
        Client::writeFrame($this->conn, $json);
    }
}
