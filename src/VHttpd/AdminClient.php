<?php

declare(strict_types=1);

namespace VPhp\VHttpd;

use VPhp\VHttpd\PhpWorker\Client as FrameClient;

final class AdminClient
{
    public function __construct(
        private string $socketPath,
        private float $connectTimeoutSeconds = 2.0,
    ) {
    }

    /**
     * @param array<string,mixed> $query
     * @return array{status:int,headers:array<string,string>,body:mixed,raw_body:string,error:string}
     */
    public function get(string $path, array $query = []): array
    {
        $client = new FrameClient($this->socketPath, $this->connectTimeoutSeconds);
        $response = $client->request([
            'mode' => 'vhttpd_admin',
            'method' => 'GET',
            'path' => $path,
            'query' => $query,
        ]);

        $rawBody = (string) ($response['body'] ?? '');
        $body = $rawBody;
        $contentType = strtolower((string) (($response['headers']['content-type'] ?? $response['headers']['Content-Type'] ?? '')));
        if ($rawBody !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if ($decoded !== null) {
                $body = $decoded;
            }
        }

        return [
            'status' => (int) ($response['status'] ?? 0),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'body' => $body,
            'raw_body' => $rawBody,
            'error' => (string) ($response['error'] ?? ''),
        ];
    }
}
