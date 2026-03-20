<?php

declare(strict_types=1);

namespace VPhp\VHttpd;

use VPhp\VHttpd\PhpWorker\Client as FrameClient;

final class GatewayClient
{
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private string $socketPath,
        private string $provider,
        private float $connectTimeoutSeconds = 2.0,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    public function send(array $payload): array
    {
        $request = $payload;
        if (!isset($request['provider']) || trim((string) $request['provider']) === '') {
            $request['provider'] = $this->provider;
        }

        return $this->post($this->providerPath(), $request);
    }

    /**
     * @param array<string,string> $metadata
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    public function sendText(
        string $instance,
        string $chatId,
        string $text,
        array $metadata = [],
    ): array {
        return $this->send([
            'instance' => $instance,
            'target_type' => 'chat_id',
            'target' => $chatId,
            'message_type' => 'text',
            'text' => $text,
            'content_fields' => [
                'text' => $text,
            ],
            'metadata' => JsonShape::objectMap($metadata),
        ]);
    }

    /**
     * @param array<string,string> $metadata
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    public function sendImage(
        string $instance,
        string $chatId,
        string $imageKey,
        array $metadata = [],
    ): array {
        return $this->send([
            'instance' => $instance,
            'target_type' => 'chat_id',
            'target' => $chatId,
            'message_type' => 'image',
            'content_fields' => [
                'image_key' => $imageKey,
            ],
            'metadata' => JsonShape::objectMap($metadata),
        ]);
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    public function uploadImageData(
        string $instance,
        string $filename,
        string $contentType,
        string $data,
        string $imageType = 'message',
    ): array {
        $this->assertImageSizeWithinLimit(strlen($data), 'image_data');

        return $this->post('/feishu/images', [
            'app' => $instance,
            'image_type' => $imageType,
            'filename' => $filename,
            'content_type' => $contentType,
            'content_length' => strlen($data),
        ], [$data]);
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    public function uploadImage(
        string $instance,
        string $filePath,
        string $imageType = 'message',
    ): array {
        $size = @filesize($filePath);
        if (is_int($size) && $size >= 0) {
            $this->assertImageSizeWithinLimit($size, 'image_file');
        }
        $raw = @file_get_contents($filePath);
        if (!is_string($raw)) {
            throw new \RuntimeException('failed_to_read_image_file');
        }

        return $this->uploadImageData(
            $instance,
            basename($filePath),
            $this->detectMimeType($filePath),
            $raw,
            $imageType,
        );
    }

    /**
     * @param array<string,string> $metadata
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    public function sendLocalImage(
        string $instance,
        string $chatId,
        string $filePath,
        array $metadata = [],
    ): array {
        $upload = $this->uploadImage($instance, $filePath);
        $imageKey = is_array($upload['body'] ?? null)
            ? trim((string) (($upload['body']['image_key'] ?? '')))
            : '';
        if ($upload['status'] < 200 || $upload['status'] >= 300 || $imageKey === '') {
            return $upload;
        }

        return $this->sendImage($instance, $chatId, $imageKey, $metadata);
    }

    /**
     * @param array<string,string> $metadata
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    public function sendRemoteImage(
        string $instance,
        string $chatId,
        string $imageUrl,
        array $metadata = [],
        float $timeoutSeconds = 10.0,
    ): array {
        $this->assertRemoteImageLengthWithinLimit($imageUrl, $timeoutSeconds);
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'follow_location' => 1,
                'max_redirects' => 3,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($imageUrl, false, $context);
        if (!is_string($raw)) {
            throw new \RuntimeException('failed_to_fetch_remote_image');
        }

        $upload = $this->uploadImageData(
            $instance,
            $this->detectRemoteFilename($imageUrl),
            $this->detectRemoteMimeType($imageUrl, $http_response_header ?? []),
            $raw,
        );
        $imageKey = is_array($upload['body'] ?? null)
            ? trim((string) (($upload['body']['image_key'] ?? '')))
            : '';
        if ($upload['status'] < 200 || $upload['status'] >= 300 || $imageKey === '') {
            return $upload;
        }

        return $this->sendImage($instance, $chatId, $imageKey, $metadata);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    /**
     * @param array<string,mixed> $body
     * @param list<string> $frames
     */
    private function post(string $path, array $body, array $frames = []): array
    {
        $client = new FrameClient($this->socketPath, $this->connectTimeoutSeconds);
        $response = $client->requestFrames([
            'mode' => 'vhttpd_gateway',
            'method' => 'POST',
            'path' => $path,
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], $frames);

        $rawBody = (string) ($response['body'] ?? '');
        $decodedBody = $rawBody;
        $contentType = strtolower((string) (($response['headers']['content-type'] ?? $response['headers']['Content-Type'] ?? '')));
        if ($rawBody !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if ($decoded !== null) {
                $decodedBody = $decoded;
            }
        }

        return [
            'status' => (int) ($response['status'] ?? 0),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'body' => $decodedBody,
            'raw_body' => $rawBody,
            'error' => (string) ($response['error'] ?? ''),
        ];
    }

    private function providerPath(): string
    {
        return match ($this->provider) {
            'feishu' => '/feishu/messages',
            default => '/upstreams/websocket/send',
        };
    }

    private function detectMimeType(string $filePath): string
    {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($filePath);
            if (is_string($detected) && trim($detected) !== '') {
                return $detected;
            }
        }

        return match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function detectRemoteFilename(string $imageUrl): string
    {
        $path = parse_url($imageUrl, PHP_URL_PATH);
        if (is_string($path)) {
            $name = basename($path);
            if ($name !== '' && $name !== '.' && $name !== '/') {
                return $name;
            }
        }

        return 'remote-image.bin';
    }

    /**
     * @param array<int,string> $responseHeaders
     */
    private function detectRemoteMimeType(string $imageUrl, array $responseHeaders): string
    {
        foreach ($responseHeaders as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (stripos($header, 'Content-Type:') !== 0) {
                continue;
            }
            $value = trim(substr($header, strlen('Content-Type:')));
            if ($value !== '') {
                return trim(explode(';', $value, 2)[0]);
            }
        }

        return $this->detectMimeType($imageUrl);
    }

    private function assertImageSizeWithinLimit(int $bytes, string $label): void
    {
        if ($bytes > self::MAX_IMAGE_BYTES) {
            throw new \RuntimeException(
                sprintf('%s_too_large: %d > %d', $label, $bytes, self::MAX_IMAGE_BYTES),
            );
        }
    }

    private function assertRemoteImageLengthWithinLimit(string $imageUrl, float $timeoutSeconds): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => $timeoutSeconds,
                'follow_location' => 1,
                'max_redirects' => 3,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $headers = @get_headers($imageUrl, true, $context);
        if (!is_array($headers)) {
            return;
        }
        $length = $headers['Content-Length'] ?? $headers['content-length'] ?? null;
        if (is_array($length)) {
            $length = end($length);
        }
        if (is_string($length) && ctype_digit(trim($length))) {
            $this->assertImageSizeWithinLimit((int) trim($length), 'remote_image');
        }
    }
}
