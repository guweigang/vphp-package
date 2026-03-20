<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu\Command;

abstract class AbstractCommand implements \JsonSerializable
{
    public function __construct(
        protected readonly \VPhp\VHttpd\Upstream\WebSocket\Feishu\Command $command,
    ) {
    }

    public function command(): \VPhp\VHttpd\Upstream\WebSocket\Feishu\Command
    {
        return $this->command;
    }

    public function toArray(): array
    {
        return $this->command->toArray();
    }

    public function eventName(): string
    {
        return $this->command->eventName();
    }

    public function provider(): string
    {
        return $this->command->provider();
    }

    public function instance(): string
    {
        return $this->command->instance();
    }

    public function targetType(): string
    {
        return $this->command->targetType();
    }

    public function target(): string
    {
        return $this->command->target();
    }

    public function messageType(): string
    {
        return $this->command->messageType();
    }

    public function uuid(): string
    {
        $data = $this->command->toArray();
        return trim((string) ($data['uuid'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        $data = $this->command->toArray();
        $metadata = $data['metadata'] ?? [];
        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function contentFields(): array
    {
        $data = $this->command->toArray();
        $contentFields = $data['content_fields'] ?? [];
        return is_array($contentFields) ? $contentFields : [];
    }

    public function rawContent(): string
    {
        $data = $this->command->toArray();
        return (string) ($data['content'] ?? '');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function decodedContent(): ?array
    {
        $rawContent = $this->rawContent();
        if ($rawContent === '') {
            return null;
        }

        $decoded = json_decode($rawContent, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function text(): string
    {
        $data = $this->command->toArray();
        $text = trim((string) ($data['text'] ?? ''));
        if ($text !== '') {
            return $text;
        }

        return trim((string) ($this->contentFields()['text'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function toDebugArray(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toDebugArray();
    }
}
