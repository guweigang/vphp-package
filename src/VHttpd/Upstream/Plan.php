<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream;

final class Plan
{
    /**
     * @param array<string,string> $requestHeaders
     * @param array<string,string> $responseHeaders
     */
    public function __construct(
        public readonly string $transport = 'http',
        public readonly string $url = '',
        public readonly string $method = 'GET',
        public readonly array $requestHeaders = [],
        public readonly string $body = '',
        public readonly string $codec = '',
        public readonly string $mapper = '',
        public readonly string $outputStreamType = 'sse',
        public readonly string $outputContentType = 'text/event-stream',
        public readonly array $responseHeaders = [],
        public readonly string $fixturePath = '',
        public readonly string $name = '',
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param array<string,string> $requestHeaders
     * @param array<string,string> $responseHeaders
     * @param array<string,mixed> $meta
     */
    public static function http(
        string $url,
        string $method = 'GET',
        array $requestHeaders = [],
        string $body = '',
        string $codec = '',
        string $mapper = '',
        string $outputStreamType = 'sse',
        string $outputContentType = 'text/event-stream',
        array $responseHeaders = [],
        string $fixturePath = '',
        string $name = '',
        array $meta = [],
    ): self {
        return new self(
            'http',
            $url,
            strtoupper($method !== '' ? $method : 'GET'),
            self::normalizeHeaders($requestHeaders),
            $body,
            trim($codec),
            trim($mapper),
            strtolower($outputStreamType) === 'text' ? 'text' : 'sse',
            $outputContentType !== ''
                ? $outputContentType
                : (strtolower($outputStreamType) === 'text'
                    ? 'text/plain; charset=utf-8'
                    : 'text/event-stream'),
            self::normalizeHeaders($responseHeaders),
            trim($fixturePath),
            trim($name),
            $meta,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'transport' => $this->transport,
            'url' => $this->url,
            'method' => $this->method,
            'request_headers' => $this->requestHeaders,
            'body' => $this->body,
            'codec' => $this->codec,
            'mapper' => $this->mapper,
            'output_stream_type' => $this->outputStreamType,
            'output_content_type' => $this->outputContentType,
            'response_headers' => $this->responseHeaders,
            'fixture_path' => $this->fixturePath,
            'name' => $this->name,
            'meta' => $this->meta,
        ];
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
