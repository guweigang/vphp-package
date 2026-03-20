<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu;

use InvalidArgumentException;
use VPhp\VHttpd\JsonShape;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Command\Factory as CommandFactory;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Command\SendCommand;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Command\UpdateCommand;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Event\AbstractEvent;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Message\AbstractMessage;

final class Command implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $data
     */
    private function __construct(
        private readonly array $data,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $event = trim((string) ($data['event'] ?? ''));
        if (!in_array($event, ['send', 'update'], true)) {
            throw new InvalidArgumentException('Feishu command event must be send or update');
        }

        $messageType = trim((string) ($data['message_type'] ?? ''));
        if ($messageType === '') {
            throw new InvalidArgumentException('Feishu command message_type is required');
        }

        $targetType = trim((string) ($data['target_type'] ?? ''));
        $target = trim((string) ($data['target'] ?? ''));
        if ($targetType === '' || $target === '') {
            throw new InvalidArgumentException('Feishu command target_type and target are required');
        }

        $normalized = $data;
        $normalized['event'] = $event;
        $normalized['provider'] = trim((string) ($data['provider'] ?? 'feishu')) ?: 'feishu';
        $normalized['instance'] = trim((string) ($data['instance'] ?? 'main')) ?: 'main';
        $normalized['message_type'] = $messageType;
        $normalized['target_type'] = $targetType;
        $normalized['target'] = $target;

        return new self($normalized);
    }

    public static function sendText(Event|Message|AbstractEvent|AbstractMessage $event, string $text): self
    {
        return new self(self::basePayload($event, 'send', 'text') + [
            'text' => $text,
            'content_fields' => [
                'text' => $text,
            ],
        ]);
    }

    /**
     * @param array<string,string> $metadata
     */
    public static function sendTextTo(
        string $targetType,
        string $target,
        string $text,
        string $instance = 'main',
        string $provider = 'feishu',
        array $metadata = [],
    ): self {
        return self::fromArray([
            'event' => 'send',
            'provider' => $provider,
            'instance' => $instance,
            'target_type' => $targetType,
            'target' => $target,
            'message_type' => 'text',
            'text' => $text,
            'content_fields' => [
                'text' => $text,
            ],
            'metadata' => $metadata,
        ]);
    }

    public static function replyText(Message|AbstractMessage $message, string $text): self
    {
        return self::sendText($message, $text);
    }

    public static function sendPost(Event|Message|AbstractEvent|AbstractMessage $event, array|\JsonSerializable $post, ?string $uuid = null): self
    {
        return self::sendRawContent($event, 'post', $post, $uuid);
    }

    public static function sendImage(Event|Message|AbstractEvent|AbstractMessage $event, string $imageKey, ?string $uuid = null): self
    {
        return self::sendWithContentFields($event, 'image', [
            'image_key' => $imageKey,
        ], $uuid);
    }

    public static function sendFile(
        Event|Message|AbstractEvent|AbstractMessage $event,
        string $fileKey,
        string $fileName = '',
        ?string $uuid = null,
    ): self {
        $fields = ['file_key' => $fileKey];
        if ($fileName !== '') {
            $fields['file_name'] = $fileName;
        }
        return self::sendWithContentFields($event, 'file', $fields, $uuid);
    }

    public static function sendAudio(
        Event|Message|AbstractEvent|AbstractMessage $event,
        string $fileKey,
        string $duration = '',
        ?string $uuid = null,
    ): self {
        $fields = ['file_key' => $fileKey];
        if ($duration !== '') {
            $fields['duration'] = $duration;
        }
        return self::sendWithContentFields($event, 'audio', $fields, $uuid);
    }

    public static function sendMedia(
        Event|Message|AbstractEvent|AbstractMessage $event,
        string $fileKey,
        string $imageKey = '',
        string $fileName = '',
        string $duration = '',
        ?string $uuid = null,
    ): self {
        $fields = ['file_key' => $fileKey];
        if ($imageKey !== '') {
            $fields['image_key'] = $imageKey;
        }
        if ($fileName !== '') {
            $fields['file_name'] = $fileName;
        }
        if ($duration !== '') {
            $fields['duration'] = $duration;
        }
        return self::sendWithContentFields($event, 'media', $fields, $uuid);
    }

    public static function sendSticker(Event|Message|AbstractEvent|AbstractMessage $event, string $fileKey, ?string $uuid = null): self
    {
        return self::sendWithContentFields($event, 'sticker', [
            'file_key' => $fileKey,
        ], $uuid);
    }

    public static function sendInteractive(Event|Message|AbstractEvent|AbstractMessage $event, array|\JsonSerializable $card, ?string $uuid = null): self
    {
        $base = self::normalizeCarrier($event);
        $payload = [
            'event' => 'send',
            'provider' => $base->provider(),
            'instance' => $base->instance(),
            'target_type' => $base->targetType(),
            'target' => $base->target(),
            'message_type' => 'interactive',
            'content' => self::encodeContent($card),
            'metadata' => $base->metadata(),
        ];
        if ($uuid !== null && $uuid !== '') {
            $payload['uuid'] = $uuid;
        }
        return new self($payload);
    }

    public static function updateText(
        Event|AbstractEvent $event,
        string $text,
        ?string $targetType = null,
        ?string $target = null,
    ): self {
        [$resolvedTargetType, $resolvedTarget] = self::resolveUpdateTarget($event, $targetType, $target);

        return new self(self::basePayload($event, 'update', 'text', $resolvedTargetType, $resolvedTarget) + [
            'text' => $text,
            'content_fields' => [
                'text' => $text,
            ],
        ]);
    }

    public static function updateInteractive(Event|AbstractEvent $event, array|\JsonSerializable $card, ?string $targetType = null, ?string $target = null): self
    {
        [$resolvedTargetType, $resolvedTarget] = self::resolveUpdateTarget($event, $targetType, $target);

        return new self(self::basePayload($event, 'update', 'interactive', $resolvedTargetType, $resolvedTarget) + [
            'content' => self::encodeContent($card),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = $this->data;
        if (array_key_exists('metadata', $data) && is_array($data['metadata'])) {
            $data['metadata'] = JsonShape::objectMap($data['metadata']);
        }
        if (array_key_exists('content_fields', $data) && is_array($data['content_fields'])) {
            $data['content_fields'] = JsonShape::objectMap($data['content_fields']);
        }
        return $data;
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

    public function eventName(): string
    {
        return (string) ($this->data['event'] ?? '');
    }

    public function provider(): string
    {
        return (string) ($this->data['provider'] ?? 'feishu');
    }

    public function instance(): string
    {
        return (string) ($this->data['instance'] ?? 'main');
    }

    public function targetType(): string
    {
        return (string) ($this->data['target_type'] ?? '');
    }

    public function target(): string
    {
        return (string) ($this->data['target'] ?? '');
    }

    public function messageType(): string
    {
        return (string) ($this->data['message_type'] ?? '');
    }

    public function isSend(): bool
    {
        return $this->eventName() === 'send';
    }

    public function isUpdate(): bool
    {
        return $this->eventName() === 'update';
    }

    public function asSend(): ?SendCommand
    {
        if (!$this->isSend()) {
            return null;
        }

        return CommandFactory::fromCommand($this);
    }

    public function asUpdate(): ?UpdateCommand
    {
        if (!$this->isUpdate()) {
            return null;
        }

        return CommandFactory::fromCommand($this);
    }

    /**
     * @param array<string,mixed> $contentFields
     */
    private static function sendWithContentFields(
        Event|Message|AbstractEvent|AbstractMessage $event,
        string $messageType,
        array $contentFields,
        ?string $uuid = null,
    ): self {
        $payload = self::basePayload($event, 'send', $messageType) + [
            'content_fields' => $contentFields,
        ];
        if ($uuid !== null && $uuid !== '') {
            $payload['uuid'] = $uuid;
        }
        return new self($payload);
    }

    private static function sendRawContent(
        Event|Message|AbstractEvent|AbstractMessage $event,
        string $messageType,
        array|\JsonSerializable $content,
        ?string $uuid = null,
    ): self {
        $payload = self::basePayload($event, 'send', $messageType) + [
            'content' => self::encodeContent($content),
        ];
        if ($uuid !== null && $uuid !== '') {
            $payload['uuid'] = $uuid;
        }
        return new self($payload);
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function resolveUpdateTarget(Event|AbstractEvent $event, ?string $targetType, ?string $target): array
    {
        $base = self::normalizeCarrier($event);
        $resolvedTargetType = $targetType;
        $resolvedTarget = $target;
        if ($resolvedTargetType === null || $resolvedTarget === null) {
            $token = $base->token();
            if ($token !== '') {
                $resolvedTargetType = 'token';
                $resolvedTarget = $token;
            } else {
                $resolvedTargetType = $resolvedTargetType ?? 'message_id';
                $resolvedTarget = $resolvedTarget ?? $base->messageId();
            }
        }

        return [$resolvedTargetType, $resolvedTarget];
    }

    private static function basePayload(
        Event|Message|AbstractEvent|AbstractMessage $event,
        string $eventName,
        string $messageType,
        ?string $targetType = null,
        ?string $target = null,
    ): array {
        $base = self::normalizeCarrier($event);
        return [
            'event' => $eventName,
            'provider' => $base->provider(),
            'instance' => $base->instance(),
            'target_type' => $targetType ?? $base->targetType(),
            'target' => $target ?? $base->target(),
            'message_type' => $messageType,
            'metadata' => $base->metadata(),
        ];
    }

    private static function normalizeCarrier(Event|Message|AbstractEvent|AbstractMessage $event): Event|Message
    {
        if ($event instanceof AbstractEvent) {
            return $event->event();
        }
        if ($event instanceof AbstractMessage) {
            return $event->message();
        }
        return $event;
    }

    private static function encodeContent(array|\JsonSerializable $content): string
    {
        return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

}
