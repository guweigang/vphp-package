<?php

declare(strict_types=1);

namespace VPhp\VHttpd\PhpWorker;

use VPhp\VHttpd\Attribute\Dispatchable;

#[Dispatchable('stream')]
final class StreamApp
{
    /** @var null|callable */
    private $onOpen;
    /** @var null|callable */
    private $onNext;
    /** @var null|callable */
    private $onClose;

    public function __construct(
        ?callable $onOpen = null,
        ?callable $onNext = null,
        ?callable $onClose = null,
    ) {
        $this->onOpen = $onOpen;
        $this->onNext = $onNext;
        $this->onClose = $onClose;
    }

    public function onOpen(callable $handler): self
    {
        $this->onOpen = $handler;
        return $this;
    }

    public function onNext(callable $handler): self
    {
        $this->onNext = $handler;
        return $this;
    }

    public function onClose(callable $handler): self
    {
        $this->onClose = $handler;
        return $this;
    }

    /**
     * @param iterable<mixed>|callable $chunks
     * @param array<string,string> $headers
     */
    public static function fromSequence(
        string $streamType,
        iterable|callable $chunks,
        int $status = 200,
        string $contentType = '',
        array $headers = [],
        int $batchSize = 1,
        int $delayMs = 0,
    ): self {
        $normalizedType = strtolower($streamType) === 'sse' ? 'sse' : 'text';
        $normalizedStatus = $status > 0 ? $status : 200;
        $normalizedContentType = $contentType !== ''
            ? $contentType
            : ($normalizedType === 'sse' ? 'text/event-stream' : 'text/plain; charset=utf-8');
        $normalizedHeaders = self::normalizeHeaders($headers + ['content-type' => $normalizedContentType]);
        $items = self::materializeChunks($chunks);
        $size = $batchSize > 0 ? $batchSize : 1;
        $sleepMicros = max(0, $delayMs) * 1000;

        return (new self())
            ->onOpen(static function (array $frame) use (
                $normalizedType,
                $normalizedStatus,
                $normalizedContentType,
                $normalizedHeaders,
                $items,
                $size
            ): array {
                [$batch, $nextCursor, $done] = StreamApp::sliceChunks($items, 0, $size);
                return [
                    'handled' => true,
                    'stream_type' => $normalizedType,
                    'status' => $normalizedStatus,
                    'content_type' => $normalizedContentType,
                    'headers' => $normalizedHeaders,
                    'state' => [
                        'cursor' => (string) $nextCursor,
                        'total' => (string) count($items),
                    ],
                    'chunks' => $batch,
                    'done' => $done,
                ];
            })
            ->onNext(static function (array $frame) use ($items, $size, $sleepMicros): array {
                $state = is_array($frame['state'] ?? null) ? $frame['state'] : [];
                $cursor = max(0, (int) ($state['cursor'] ?? '0'));
                if ($sleepMicros > 0) {
                    usleep($sleepMicros);
                }
                [$batch, $nextCursor, $done] = StreamApp::sliceChunks($items, $cursor, $size);
                return [
                    'handled' => true,
                    'state' => [
                        'cursor' => (string) $nextCursor,
                        'total' => (string) count($items),
                    ],
                    'chunks' => $batch,
                    'done' => $done,
                ];
            })
            ->onClose(static function (array $frame): array {
                return [
                    'handled' => true,
                    'done' => true,
                ];
            });
    }

    public static function fromStreamResponse(
        object $response,
        int $batchSize = 1,
        int $delayMs = 0,
    ): self {
        $streamType = property_exists($response, 'stream_type')
            ? (string) $response->stream_type
            : ((method_exists($response, 'stream_type') ? (string) $response->stream_type() : 'text'));
        $status = property_exists($response, 'status') ? (int) $response->status : 200;
        $contentType = property_exists($response, 'content_type')
            ? (string) $response->content_type
            : ($streamType === 'sse' ? 'text/event-stream' : 'text/plain; charset=utf-8');
        $headers = method_exists($response, 'headers') ? (array) $response->headers() : [];
        $chunks = method_exists($response, 'chunks') ? $response->chunks() : [];
        if (is_callable($chunks)) {
            $chunks = $chunks();
        }
        if (!is_iterable($chunks)) {
            $chunks = [];
        }

        return self::fromSequence(
            $streamType,
            $chunks,
            $status,
            $contentType,
            is_array($headers) ? $headers : [],
            $batchSize,
            $delayMs,
        );
    }

    /** @param array<string,mixed> $frame */
    public function handle(array $frame): mixed
    {
        return $this->handle_stream($frame);
    }

    /** @param array<string,mixed> $frame */
    public function handle_stream(array $frame): mixed
    {
        $event = (string) ($frame['event'] ?? '');
        return match ($event) {
            'open' => $this->onOpen !== null ? ($this->onOpen)($frame) : null,
            'next' => $this->onNext !== null ? ($this->onNext)($frame) : null,
            'close' => $this->onClose !== null ? ($this->onClose)($frame) : null,
            default => null,
        };
    }

    /** @param array<string,mixed> $frame */
    public function handleStream(array $frame): mixed
    {
        return $this->handle_stream($frame);
    }

    /**
     * @param iterable<mixed>|callable $chunks
     * @return list<mixed>
     */
    private static function materializeChunks(iterable|callable $chunks): array
    {
        if (is_callable($chunks)) {
            $chunks = $chunks();
        }
        if (!is_iterable($chunks)) {
            return [];
        }
        $items = [];
        foreach ($chunks as $chunk) {
            $items[] = $chunk;
        }
        return $items;
    }

    /**
     * @param list<mixed> $items
     * @return array{0:list<mixed>,1:int,2:bool}
     */
    private static function sliceChunks(array $items, int $cursor, int $batchSize): array
    {
        if ($cursor < 0) {
            $cursor = 0;
        }
        $total = count($items);
        if ($cursor >= $total) {
            return [[], $total, true];
        }
        $batch = array_slice($items, $cursor, $batchSize);
        $nextCursor = $cursor + count($batch);
        return [$batch, $nextCursor, $nextCursor >= $total];
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $clean = strtolower(trim((string) $name));
            if ($clean === '') {
                continue;
            }
            $normalized[$clean] = (string) $value;
        }
        return $normalized;
    }
}
