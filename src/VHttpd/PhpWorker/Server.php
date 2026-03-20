<?php

declare(strict_types=1);

namespace VPhp\VHttpd\PhpWorker;

use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Throwable;
use TypeError;
use VPhp\VHttpd\JsonShape;
use VPhp\VHttpd\Upstream\Plan;
use VPhp\VHttpd\PhpWorker\WebSocket\CommandBuffer;
use VPhp\VHttpd\PhpWorker\WebSocket\CommandSink;
use VPhp\VHttpd\PhpWorker\WebSocket\Connection;
use VPhp\VSlim\Mcp\App as McpApp;
use VPhp\VSlim\WebSocket\App as WebSocketApp;

/**
 * PHP Worker for vhttpd proxy mode.
 *
 * Protocol:
 * - request:  [4-byte big-endian length][json payload]
 * - response: [4-byte big-endian length][json payload]
 *
 * Usage:
 *   php php/package/bin/php-worker --socket /tmp/vphp_worker.sock
 */

final class Server
{
    private string $socketPath;
    private ?string $appBootstrapPath;
    private int $parentPid = 0;
    /** @var resource|null */
    private $server = null;
    /** @var mixed */
    private $appHandler = null;
    private bool $appLoaded = false;

    public function __construct(
        string $socketPath,
        ?string $appBootstrapPath = null,
    ) {
        $this->socketPath = $socketPath;
        $this->appBootstrapPath = $appBootstrapPath;
        $this->parentPid = (int) (getenv('VHTTPD_PARENT_PID') ?: 0);
    }

    public function run(): void
    {
        $this->prepareSocketPath();
        $uri = "unix://" . $this->socketPath;
        $errno = 0;
        $errstr = "";

        $this->server = @stream_socket_server($uri, $errno, $errstr);
        if (!is_resource($this->server)) {
            $extra = '';
            $transports = @stream_get_transports();
            if (!is_array($transports) || !in_array('unix', $transports, true)) {
                $extra = ' (unix transport unavailable in current PHP runtime)';
            } elseif ($this->socketPath === '') {
                $extra = ' (empty --socket path)';
            } elseif (str_starts_with($this->socketPath, '/')) {
                $dir = dirname($this->socketPath);
                if (!is_dir($dir)) {
                    $extra = " (socket directory missing: {$dir})";
                }
            }
            if ($extra === '' && $errstr === '' && $errno === 0) {
                $extra = ' (runtime refused unix socket creation; verify sandbox/SELinux/AppArmor policy and write permission)';
            }
            fwrite(STDERR, "worker_start_failed: {$errstr} ({$errno})\n");
            if ($extra !== '') {
                fwrite(STDERR, "worker_start_hint:{$extra}\n");
            }
            exit(1);
        }

        @chmod($this->socketPath, 0660);
        fwrite(STDOUT, "worker_started socket={$this->socketPath}\n");

        while (true) {
            $conn = @stream_socket_accept($this->server, 1.0);
            if ($conn === false) {
                if ($this->shouldExitBecauseParentGone()) {
                    break;
                }
                continue;
            }
            if (!is_resource($conn)) {
                continue;
            }
            try {
                $this->handleConnection($conn);
            } catch (Throwable $e) {
                fwrite(STDERR, "worker_connection_error: " . $e->getMessage() . "\n");
                @fclose($conn);
            }
            if ($this->shouldExitBecauseParentGone()) {
                break;
            }
        }

        if (is_resource($this->server)) {
            @fclose($this->server);
        }
    }

    private function shouldExitBecauseParentGone(): bool
    {
        if ($this->parentPid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            /** @var bool $alive */
            $alive = @posix_kill($this->parentPid, 0);
            return !$alive;
        }
        return false;
    }

    /** @param resource $conn */
    private function handleConnection($conn): void
    {
        stream_set_blocking($conn, true);

        while (true) {
            $payload = $this->readFrame($conn);
            if ($payload === null) {
                break;
            }

            $req = json_decode($payload, true);
            if (!is_array($req)) {
                $this->writeFrame(
                    $conn,
                    json_encode(
                        [
                            "id" => "",
                            "status" => 400,
                            "headers" => [
                                "content-type" => "text/plain; charset=utf-8",
                            ],
                            "body" => "Bad Request: invalid JSON",
                        ],
                        JSON_UNESCAPED_UNICODE,
                    ),
                );
                continue;
            }

            if (($req['mode'] ?? '') === 'websocket') {
                $this->handleWebSocketFrame($conn, $req);
                continue;
            }
            if (($req['mode'] ?? '') === 'websocket_dispatch') {
                $response = $this->handleWebSocketDispatch($req);
                $this->writeFrame($conn, json_encode($response, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if ($this->isStreamRequest($req)) {
                $response = $this->handleStream($req);
                $this->writeFrame($conn, json_encode($response, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if ($this->isMcpRequest($req)) {
                $response = $this->handleMcp($req);
                $this->writeFrame($conn, json_encode($response, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if ($this->isWebSocketUpstreamRequest($req)) {
                $response = $this->handleWebSocketUpstream($req);
                $this->writeFrame($conn, json_encode($response, JSON_UNESCAPED_UNICODE));
                continue;
            }

            try {
                $result = $this->dispatchRequestResult($req);
            } catch (Throwable $fatal) {
                $id = (string) ($req["id"] ?? "");
                $safe = $this->res($id, 500, "Internal Server Error", [
                    "x-worker-error" => $fatal->getMessage(),
                    "x-worker-error-class" => "worker_runtime_error",
                    "x-worker-exception" => get_class($fatal),
                ]);
                $this->writeFrame(
                    $conn,
                    json_encode($safe, JSON_UNESCAPED_UNICODE),
                );
                continue;
            }
            $id = (string) ($req["id"] ?? "");
            $stream = $this->normalizeStreamResponseObject($result);
            if ($stream !== null) {
                $this->writeStreamResponse($conn, $id, $stream);
                continue;
            }
            $upstreamPlan = $this->normalizeUpstreamPlanObject($result);
            if ($upstreamPlan !== null) {
                $this->writeUpstreamPlan($conn, $id, $upstreamPlan);
                continue;
            }
            if (!is_array($result)) {
                $result = $this->res($id, 500, "Internal Server Error", [
                    "x-worker-error" => "Invalid worker response type",
                    "x-worker-error-class" => "app_contract_error",
                ]);
            }
            $this->writeFrame($conn, json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        fclose($conn);
    }

    /** @param resource $conn
     *  @param array<string,mixed> $frame
     */
    private function handleWebSocketFrame($conn, array $frame): void
    {
        $id = (string) ($frame['id'] ?? '');
        $ws = new Connection($conn, $id);

        try {
            $handler = $this->resolveWebSocketHandler($this->loadAppHandler());
            if ($handler === null) {
                $ws->close(1011, 'WebSocket handler unavailable', 501);
                $ws->done();
                return;
            }

            $result = $this->dispatchWebSocketHandler($handler, $frame, $ws);
            $this->normalizeWebSocketHandlerResult($frame, $result, $ws);
        } catch (Throwable $e) {
            $ws->error($e->getMessage(), $this->classifyThrowable($e));
            if (!$ws->closed()) {
                $status = ($frame['event'] ?? '') === 'open' ? 500 : 0;
                $ws->close(1011, 'worker error', $status);
            }
        }

        $ws->done();
    }

    /** @param array<string,mixed> $frame
     *  @return array<string,mixed>
     */
    private function handleWebSocketDispatch(array $frame): array
    {
        $id = (string) ($frame['id'] ?? '');
        $buffer = new CommandBuffer($id);

        try {
            $handler = $this->resolveWebSocketHandler($this->loadAppHandler());
            if ($handler === null) {
                return [
                    'mode' => 'websocket_dispatch',
                    'event' => 'error',
                    'id' => $id,
                    'error_class' => 'app_contract_error',
                    'error' => 'WebSocket handler unavailable',
                ];
            }

            $result = $this->dispatchWebSocketHandler($handler, $frame, $buffer);
            $this->normalizeWebSocketHandlerResult($frame, $result, $buffer);

            return [
                'mode' => 'websocket_dispatch',
                'event' => 'result',
                'id' => $id,
                'accepted' => $buffer->accepted(),
                'closed' => $buffer->closed(),
                'commands' => $buffer->commands(),
            ];
        } catch (Throwable $e) {
            return [
                'mode' => 'websocket_dispatch',
                'event' => 'error',
                'id' => $id,
                'error_class' => $this->classifyThrowable($e),
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @param array<string,mixed> $frame
     *  @return array<string,mixed>
     */
    private function handleStream(array $frame): array
    {
        $id = (string) ($frame['id'] ?? '');

        try {
            $handler = $this->resolveStreamHandler($this->loadAppHandler());
            if ($handler === null) {
                return [
                    'mode' => 'stream',
                    'strategy' => 'dispatch',
                    'event' => 'result',
                    'id' => $id,
                    'handled' => false,
                    'done' => true,
                    'stream_type' => '',
                    'content_type' => '',
                    'headers' => JsonShape::objectMap([]),
                    'state' => JsonShape::objectMap([]),
                    'chunks' => [],
                ];
            }

            $result = $this->dispatchStreamHandler($handler, $frame);
            return $this->normalizeStreamDispatchResult($frame, $result);
        } catch (Throwable $e) {
            return [
                'mode' => 'stream',
                'strategy' => 'dispatch',
                'event' => 'error',
                'id' => $id,
                'error_class' => $this->classifyThrowable($e),
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @param array<string,mixed> $frame
     *  @return array<string,mixed>
     */
    private function handleMcp(array $frame): array
    {
        $id = (string) ($frame['id'] ?? '');

        try {
            $handler = $this->resolveMcpHandler($this->loadAppHandler());
            if ($handler === null) {
                return [
                    'mode' => 'mcp',
                    'event' => 'result',
                    'id' => $id,
                    'handled' => false,
                    'status' => 501,
                    'headers' => JsonShape::objectMap([
                        'content-type' => 'application/json; charset=utf-8',
                    ]),
                    'body' => json_encode([
                        'jsonrpc' => '2.0',
                        'id' => null,
                        'error' => [
                            'code' => -32601,
                            'message' => 'MCP handler unavailable',
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'protocol_version' => (string) ($frame['protocol_version'] ?? ''),
                    'session_id' => '',
                ];
            }

            $result = $this->dispatchMcpHandler($handler, $frame);
            return $this->normalizeMcpDispatchResult($frame, $result);
        } catch (Throwable $e) {
            return [
                'mode' => 'mcp',
                'event' => 'error',
                'id' => $id,
                'error_class' => $this->classifyThrowable($e),
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @param array<string,mixed> $frame
     *  @return array<string,mixed>
     */
    private function handleWebSocketUpstream(array $frame): array
    {
        $id = (string) ($frame['id'] ?? '');

        try {
            $handler = $this->resolveWebSocketUpstreamHandler($this->loadAppHandler());
            if ($handler === null) {
                return [
                    'mode' => 'websocket_upstream',
                    'event' => 'result',
                    'id' => $id,
                    'handled' => false,
                    'commands' => [],
                ];
            }

            $result = $this->dispatchWebSocketUpstreamHandler($handler, $frame);
            return $this->normalizeWebSocketUpstreamDispatchResult($frame, $result);
        } catch (Throwable $e) {
            return [
                'mode' => 'websocket_upstream',
                'event' => 'error',
                'id' => $id,
                'error_class' => $this->classifyThrowable($e),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function dispatchRequest(array $req): array
    {
        $id = (string) ($req["id"] ?? "");
        if (($req['mode'] ?? '') === 'websocket_dispatch') {
            return $this->handleWebSocketDispatch($req);
        }
        if ($this->isStreamRequest($req)) {
            return $this->handleStream($req);
        }
        if ($this->isMcpRequest($req)) {
            return $this->handleMcp($req);
        }
        if ($this->isWebSocketUpstreamRequest($req)) {
            return $this->handleWebSocketUpstream($req);
        }
        $result = $this->dispatchRequestResult($req);
        if ($this->normalizeStreamResponseObject($result) !== null) {
            return $this->res($id, 500, "Streaming response requires vhttpd stream mode", [
                "x-worker-error-class" => "app_contract_error",
            ]);
        }
        if ($this->normalizeUpstreamPlanObject($result) !== null) {
            return $this->res($id, 500, "Upstream plan requires vhttpd upstream mode", [
                "x-worker-error-class" => "app_contract_error",
            ]);
        }
        if (!is_array($result)) {
            return $this->res($id, 500, "Internal Server Error", [
                "x-worker-error-class" => "app_contract_error",
            ]);
        }
        return $result;
    }

    /** @param array<string,mixed> $req */
    private function dispatchRequestResult(array $req): mixed
    {
        $id = (string) ($req["id"] ?? "");
        $envelope = $this->normalizeRequestEnvelope($req);
        $method = strtoupper((string) ($envelope["method"] ?? "GET"));
        $path = (string) ($envelope["path"] ?? "/");
        $query = $this->readAssocMap($envelope, "query");
        $body = (string) ($envelope["body"] ?? "");

        try {
            $appHandler = $this->loadAppHandler();
            if ($appHandler !== null) {
                $psrRequest = \VPhp\VHttpd\Psr7Adapter::canBuildServerRequest()
                    ? \VPhp\VHttpd\Psr7Adapter::buildServerRequest($envelope)
                    : null;
                $appResult = $this->dispatchLoadedApp(
                    $appHandler,
                    $psrRequest,
                    $envelope,
                );
                $streamResult = $this->normalizeStreamResponseObject($appResult);
                if ($streamResult !== null) {
                    return $streamResult;
                }
                $upstreamPlan = $this->normalizeUpstreamPlanObject($appResult);
                if ($upstreamPlan !== null) {
                    return $upstreamPlan;
                }
                try {
                    return $this->normalizeAppResponse($id, $appResult);
                } catch (Throwable $normalizeError) {
                    return $this->res($id, 500, "Internal Server Error", [
                        "x-worker-error" => "Failed to normalize app response: " . $normalizeError->getMessage(),
                        "x-worker-error-class" => "app_contract_error",
                        "x-worker-exception" => get_class($normalizeError),
                        "x-worker-result-type" => is_object($appResult) ? get_class($appResult) : gettype($appResult),
                    ]);
                }
            }

            if (function_exists("vslim_handle_request")) {
                $res = vslim_handle_request($envelope);
                if (is_array($res)) {
                    return [
                        "id" => $id,
                        "status" => (int) ($res["status"] ?? 500),
                        "content_type" =>
                            (string) ($res["content_type"] ??
                                "text/plain; charset=utf-8"),
                        "headers" => [
                            "content-type" =>
                                (string) ($res["content_type"] ??
                                    "text/plain; charset=utf-8"),
                        ],
                        "body" => (string) ($res["body"] ?? ""),
                    ];
                }
            }

            if (\VPhp\VHttpd\Psr7Adapter::canBuildServerRequest()) {
                $psrRequest = \VPhp\VHttpd\Psr7Adapter::buildServerRequest($envelope);
                if ($psrRequest !== null) {
                    return $this->resJson($id, 200, [
                        "psr7" => true,
                        "class" => get_class($psrRequest),
                        "method" => $psrRequest->method ?? "",
                        "uri" => $psrRequest->uri ?? "",
                    ]);
                }
            }

            if ($path === "/health") {
                if ($method !== "GET") {
                    return $this->res($id, 405, "Method Not Allowed");
                }
                return $this->res($id, 200, "OK");
            }

            if (preg_match('#^/users/([^/]+)$#', $path, $m) === 1) {
                if ($method !== "GET") {
                    return $this->res($id, 405, "Method Not Allowed");
                }
                $uid = $m[1];
                return $this->resJson($id, 200, [
                    "user" => $uid,
                    "source" => "php-worker",
                    "query" => $query,
                ]);
            }

            if ($path === "/echo") {
                if ($method !== "POST") {
                    return $this->res($id, 405, "Method Not Allowed");
                }
                return $this->resJson($id, 200, [
                    "echo" => $body,
                ]);
            }

            if ($path === "/panic") {
                throw new RuntimeException("synthetic worker panic");
            }

            return $this->res($id, 404, "Not Found");
        } catch (Throwable $e) {
            $errorClass = $this->classifyThrowable($e);
            return $this->res($id, 500, "Internal Server Error", [
                "x-worker-error" => $e->getMessage(),
                "x-worker-error-class" => $errorClass,
                "x-worker-exception" => get_class($e),
            ]);
        }
    }

    /** @param resource $conn */
    private function writeStreamResponse($conn, string $id, StreamResponse $stream): void
    {
        $headers = $this->normalizeHeaderMap($stream->headers);
        if (!isset($headers["content-type"])) {
            $headers["content-type"] = $stream->contentType;
        }

        $this->writeFrame(
            $conn,
            json_encode(
                [
                    "mode" => "stream",
                    "strategy" => "direct",
                    "event" => "start",
                    "id" => $id,
                    "status" => $stream->status,
                    "stream_type" => $stream->streamType,
                    "content_type" => $headers["content-type"],
                    "headers" => $headers,
                ],
                JSON_UNESCAPED_UNICODE,
            ),
        );

        try {
            foreach ($stream->chunks as $chunk) {
                $frame = [
                    "mode" => "stream",
                    "strategy" => "direct",
                    "event" => "chunk",
                    "id" => $id,
                    "data" => "",
                ];
                if ($stream->streamType === "sse") {
                    if (is_array($chunk)) {
                        $frame["sse_id"] = (string) ($chunk["id"] ?? "");
                        $frame["sse_event"] = (string) ($chunk["event"] ?? "");
                        $frame["sse_retry"] = (int) ($chunk["retry"] ?? 0);
                        $frame["data"] = (string) ($chunk["data"] ?? "");
                    } else {
                        $frame["sse_event"] = "message";
                        $frame["data"] = (string) $chunk;
                    }
                } else {
                    $frame["data"] = is_array($chunk)
                        ? (string) ($chunk["data"] ?? "")
                        : (string) $chunk;
                }

                $this->writeFrame(
                    $conn,
                    json_encode($frame, JSON_UNESCAPED_UNICODE),
                );
            }
        } catch (Throwable $e) {
            $this->writeFrame(
                $conn,
                json_encode(
                    [
                        "mode" => "stream",
                        "strategy" => "direct",
                        "event" => "error",
                        "id" => $id,
                        "error_class" => $this->classifyThrowable($e),
                        "error" => $e->getMessage(),
                    ],
                    JSON_UNESCAPED_UNICODE,
                ),
            );
        }

        $this->writeFrame(
            $conn,
            json_encode(
                [
                    "mode" => "stream",
                    "strategy" => "direct",
                    "event" => "end",
                    "id" => $id,
                ],
                JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /** @param resource $conn */
    private function writeUpstreamPlan($conn, string $id, Plan $plan): void
    {
        $payload = $plan->toArray();
        $payload['mode'] = 'stream';
        $payload['strategy'] = 'upstream_plan';
        $payload['event'] = 'start';
        $payload['id'] = $id;
        $payload['request_headers'] = JsonShape::objectMap($this->normalizeHeaderMap(
            isset($payload['request_headers']) && is_array($payload['request_headers'])
                ? $payload['request_headers']
                : [],
        ));
        $payload['response_headers'] = JsonShape::objectMap($this->normalizeHeaderMap(
            isset($payload['response_headers']) && is_array($payload['response_headers'])
                ? $payload['response_headers']
                : [],
        ));
        if (!isset($payload['meta']) || !is_array($payload['meta'])) {
            $payload['meta'] = (object) [];
        } elseif ($payload['meta'] === []) {
            $payload['meta'] = (object) [];
        }

        $this->writeFrame($conn, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    private function normalizeRequestEnvelope(array $req): array
    {
        $method = strtoupper((string) ($req["method"] ?? "GET"));
        $path = (string) ($req["path"] ?? "/");
        $query = $this->readAssocMap($req, "query");
        $headers = $this->normalizeHeaderMap($this->readAssocMap($req, "headers"));
        $cookies = $this->readAssocMap($req, "cookies");
        $attributes = $this->readAssocMap($req, "attributes");
        $server = $this->readAssocMap($req, "server");
        $uploadedFiles = $this->readList($req, "uploaded_files");

        return [
            "method" => $method,
            "path" => $this->rebuildPath($path, $query),
            "body" => (string) ($req["body"] ?? ""),
            "scheme" => (string) ($req["scheme"] ?? "http"),
            "host" => (string) ($req["host"] ?? ""),
            "port" => (string) ($req["port"] ?? ""),
            "protocol_version" => (string) ($req["protocol_version"] ?? "1.1"),
            "remote_addr" => (string) ($req["remote_addr"] ?? ""),
            "query" => $query,
            "headers" => $headers,
            "cookies" => $cookies,
            "attributes" => $attributes,
            "server" => $server,
            "uploaded_files" => $uploadedFiles,
        ];
    }

    /** @return mixed */
    private function loadAppHandler(): mixed
    {
        if ($this->appLoaded) {
            return $this->appHandler;
        }
        $this->appLoaded = true;

        $path = $this->resolveAppBootstrapPath();
        if ($path === null || !is_file($path)) {
            return $this->appHandler = null;
        }

        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $loaded = require $path;
        if (is_callable($loaded)) {
            return $this->appHandler = $loaded;
        }
        $psr15Stack = $this->buildPsr15DispatcherFromBootstrap($loaded);
        if ($psr15Stack !== null) {
            return $this->appHandler = $psr15Stack;
        }
        if (
            is_array($loaded)
            && (
                array_key_exists('http', $loaded)
                || array_key_exists('websocket', $loaded)
                || array_key_exists('stream', $loaded)
                || array_key_exists('mcp', $loaded)
                || array_key_exists('websocket_upstream', $loaded)
            )
        ) {
            return $this->appHandler = $loaded;
        }
        if (
            $loaded instanceof WebSocketApp
            || $loaded instanceof StreamApp
            || $loaded instanceof McpApp
        ) {
            return $this->appHandler = $loaded;
        }
        if (is_object($loaded) && $this->hasDispatchableKind($loaded, 'http', 'websocket', 'stream', 'mcp', 'websocket_upstream')) {
            return $this->appHandler = $loaded;
        }
        if (is_object($loaded) && $this->isPsr15RequestHandler($loaded)) {
            return $this->appHandler = $loaded;
        }
        if (is_object($loaded) && $loaded instanceof \VSlim\App) {
            return $this->appHandler = $loaded;
        }
        if (function_exists("vslim_httpd_app")) {
            /** @var callable $fn */
            $fn = "vslim_httpd_app";
            return $this->appHandler = $fn;
        }
        return $this->appHandler = null;
    }

    private function dispatchLoadedApp(
        mixed $appHandler,
        ?object $psrRequest,
        array $envelope,
    ): mixed {
        if (is_array($appHandler)) {
            if (array_key_exists('http', $appHandler)) {
                return $this->dispatchLoadedApp($appHandler['http'], $psrRequest, $envelope);
            }
            throw new RuntimeException("Bootstrap did not provide an HTTP handler");
        }
        if ($this->isPsr15RequestHandler($appHandler)) {
            if ($psrRequest === null) {
                throw new RuntimeException(
                    "PSR-15 handler requires a PSR-7 request object",
                );
            }
            return $appHandler->handle($psrRequest);
        }
        if (is_callable($appHandler)) {
            return $psrRequest !== null
                ? $appHandler($psrRequest, $envelope)
                : $appHandler($envelope);
        }
        if (is_object($appHandler) && $appHandler instanceof \VSlim\App) {
            if ($psrRequest !== null) {
                return \VPhp\VSlim\Psr7Adapter::dispatch($appHandler, $psrRequest);
            }
            return $appHandler->dispatch_envelope($envelope);
        }
        if (is_object($appHandler) && $this->hasDispatchableKind($appHandler, 'http')) {
            if (method_exists($appHandler, 'dispatchEnvelopeWorker')) {
                return $appHandler->dispatchEnvelopeWorker($envelope);
            }
            if (method_exists($appHandler, 'dispatch_envelope_worker')) {
                return $appHandler->dispatch_envelope_worker($envelope);
            }
            if (method_exists($appHandler, 'dispatchEnvelope')) {
                return $appHandler->dispatchEnvelope($envelope);
            }
            if (method_exists($appHandler, 'dispatch_envelope')) {
                return $appHandler->dispatch_envelope($envelope);
            }
            if (method_exists($appHandler, 'dispatchRequest')) {
                return $appHandler->dispatchRequest($envelope);
            }
            if (method_exists($appHandler, 'dispatch_request')) {
                return $appHandler->dispatch_request($envelope);
            }
        }
        throw new RuntimeException("Unsupported app bootstrap result");
    }

    private function resolveWebSocketHandler(mixed $loaded): mixed
    {
        if ($loaded instanceof WebSocketApp) {
            return $loaded;
        }
        if (is_object($loaded) && $this->hasDispatchableKind($loaded, 'websocket')) {
            return $loaded;
        }
        if (is_array($loaded) && array_key_exists('websocket', $loaded)) {
            return $loaded['websocket'];
        }
        if (
            is_object($loaded)
            && (
                method_exists($loaded, 'handleWebSocket')
                || method_exists($loaded, 'handle_websocket')
                || method_exists($loaded, 'onWebSocketOpen')
                || method_exists($loaded, 'on_websocket_open')
                || method_exists($loaded, 'onWebSocketMessage')
                || method_exists($loaded, 'on_websocket_message')
                || method_exists($loaded, 'onWebSocketClose')
                || method_exists($loaded, 'on_websocket_close')
            )
        ) {
            return $loaded;
        }
        if (is_callable($loaded)) {
            return $loaded;
        }
        return null;
    }

    private function resolveStreamHandler(mixed $loaded): mixed
    {
        if ($loaded instanceof StreamApp) {
            return $loaded;
        }
        if (is_object($loaded) && $this->hasDispatchableKind($loaded, 'stream')) {
            return $loaded;
        }
        if (is_array($loaded) && array_key_exists('stream', $loaded)) {
            return $loaded['stream'];
        }
        if (
            is_object($loaded)
            && (
                method_exists($loaded, 'handleStream')
                || method_exists($loaded, 'handle_stream')
            )
        ) {
            return $loaded;
        }
        if (is_callable($loaded)) {
            return null;
        }
        return null;
    }

    private function resolveMcpHandler(mixed $loaded): mixed
    {
        if ($loaded instanceof McpApp) {
            return $loaded;
        }
        if (is_object($loaded) && $this->hasDispatchableKind($loaded, 'mcp')) {
            return $loaded;
        }
        if (is_array($loaded) && array_key_exists('mcp', $loaded)) {
            return $loaded['mcp'];
        }
        if (
            is_object($loaded)
            && (
                method_exists($loaded, 'handleMcp')
                || method_exists($loaded, 'handle_mcp')
            )
        ) {
            return $loaded;
        }
        return null;
    }

    private function resolveWebSocketUpstreamHandler(mixed $loaded): mixed
    {
        if (is_object($loaded) && $this->hasDispatchableKind($loaded, 'websocket_upstream')) {
            return $loaded;
        }
        if (is_array($loaded) && array_key_exists('websocket_upstream', $loaded)) {
            return $loaded['websocket_upstream'];
        }
        if (
            is_object($loaded)
            && (
                method_exists($loaded, 'handleWebSocketUpstream')
                || method_exists($loaded, 'handle_websocket_upstream')
            )
        ) {
            return $loaded;
        }
        if (is_callable($loaded)) {
            return null;
        }
        return null;
    }

    /** @param array<string,mixed> $frame */
    private function dispatchWebSocketHandler(
        mixed $handler,
        array $frame,
        CommandSink $conn,
    ): mixed {
        if ($handler instanceof WebSocketApp) {
            return $handler->handle($frame, $conn);
        }
        if (is_callable($handler)) {
            return $handler($frame, $conn);
        }
        if (is_object($handler) && method_exists($handler, 'handleWebSocket')) {
            return $handler->handleWebSocket($frame, $conn);
        }
        if (is_object($handler) && method_exists($handler, 'handle_websocket')) {
            return $handler->handle_websocket($frame, $conn);
        }

        $event = (string) ($frame['event'] ?? '');
        if ($event === 'open' && is_object($handler) && method_exists($handler, 'onWebSocketOpen')) {
            return $handler->onWebSocketOpen($conn, $frame);
        }
        if ($event === 'open' && is_object($handler) && method_exists($handler, 'on_websocket_open')) {
            return $handler->on_websocket_open($conn, $frame);
        }
        if ($event === 'message' && is_object($handler) && method_exists($handler, 'onWebSocketMessage')) {
            return $handler->onWebSocketMessage($conn, (string) ($frame['data'] ?? ''), $frame);
        }
        if ($event === 'message' && is_object($handler) && method_exists($handler, 'on_websocket_message')) {
            return $handler->on_websocket_message($conn, (string) ($frame['data'] ?? ''), $frame);
        }
        if ($event === 'close' && is_object($handler) && method_exists($handler, 'onWebSocketClose')) {
            return $handler->onWebSocketClose(
                $conn,
                (int) ($frame['code'] ?? 1000),
                (string) ($frame['reason'] ?? ''),
                $frame,
            );
        }
        if ($event === 'close' && is_object($handler) && method_exists($handler, 'on_websocket_close')) {
            return $handler->on_websocket_close(
                $conn,
                (int) ($frame['code'] ?? 1000),
                (string) ($frame['reason'] ?? ''),
                $frame,
            );
        }

        return null;
    }

    /** @param array<string,mixed> $frame */
    private function dispatchStreamHandler(
        mixed $handler,
        array $frame,
    ): mixed {
        if ($handler instanceof StreamApp) {
            return $handler->handle($frame);
        }
        if (is_callable($handler)) {
            return $handler($frame);
        }
        if (is_object($handler) && method_exists($handler, 'handleStream')) {
            return $handler->handleStream($frame);
        }
        if (is_object($handler) && method_exists($handler, 'handle_stream')) {
            return $handler->handle_stream($frame);
        }
        return null;
    }

    /** @param array<string,mixed> $frame */
    private function dispatchMcpHandler(
        mixed $handler,
        array $frame,
    ): mixed {
        if ($handler instanceof McpApp) {
            return $handler->handle($frame);
        }
        if (is_callable($handler)) {
            return $handler($frame);
        }
        if (is_object($handler) && method_exists($handler, 'handleMcp')) {
            return $handler->handleMcp($frame);
        }
        if (is_object($handler) && method_exists($handler, 'handle_mcp')) {
            return $handler->handle_mcp($frame);
        }
        return null;
    }

    /** @param array<string,mixed> $frame */
    private function dispatchWebSocketUpstreamHandler(
        mixed $handler,
        array $frame,
    ): mixed {
        if (is_callable($handler)) {
            return $handler($frame);
        }
        if (is_object($handler) && method_exists($handler, 'handleWebSocketUpstream')) {
            return $handler->handleWebSocketUpstream($frame);
        }
        if (is_object($handler) && method_exists($handler, 'handle_websocket_upstream')) {
            return $handler->handle_websocket_upstream($frame);
        }
        return null;
    }

    /** @param array<string,mixed> $frame
     *  @return array<string,mixed>
     */
    private function normalizeStreamDispatchResult(array $frame, mixed $result): array
    {
        $id = (string) ($frame['id'] ?? '');
        $event = (string) ($frame['event'] ?? '');

        if ($result === null || $result === false) {
            return [
                'mode' => 'stream',
                'strategy' => 'dispatch',
                'event' => 'result',
                'id' => $id,
                'handled' => $event !== 'open',
                'done' => true,
                'stream_type' => '',
                'content_type' => '',
                    'headers' => JsonShape::objectMap([]),
                    'state' => JsonShape::objectMap([]),
                'chunks' => [],
            ];
        }

        if (!is_array($result)) {
            throw new RuntimeException('Invalid stream result type');
        }

        $streamType = (string) ($result['stream_type'] ?? ($event === 'open' ? 'sse' : ''));
        $contentType = (string) ($result['content_type'] ?? ($streamType === 'sse'
            ? 'text/event-stream'
            : 'text/plain; charset=utf-8'));
        $headers = isset($result['headers']) && is_array($result['headers'])
            ? $this->normalizeHeaderMap($result['headers'])
            : [];
        $state = isset($result['state']) && is_array($result['state'])
            ? $this->normalizeHeaderMap($result['state'])
            : [];
        $chunks = isset($result['chunks']) && is_array($result['chunks'])
            ? array_values($result['chunks'])
            : [];

        return [
            'mode' => 'stream',
            'strategy' => 'dispatch',
            'event' => 'result',
            'id' => $id,
            'handled' => (bool) ($result['handled'] ?? true),
            'done' => (bool) ($result['done'] ?? false),
            'stream_type' => $streamType,
            'content_type' => $contentType,
            'headers' => JsonShape::objectMap($headers),
            'state' => JsonShape::objectMap($state),
            'chunks' => $chunks,
        ];
    }

    /** @param array<string,mixed> $frame
     *  @return array<string,mixed>
     */
    private function normalizeMcpDispatchResult(array $frame, mixed $result): array
    {
        $id = (string) ($frame['id'] ?? '');
        $protocolVersion = (string) ($frame['protocol_version'] ?? '');

        if ($result === null || $result === false) {
            return [
                'mode' => 'mcp',
                'event' => 'result',
                'id' => $id,
                'handled' => false,
                'status' => 501,
                'headers' => JsonShape::objectMap([
                    'content-type' => 'application/json; charset=utf-8',
                ]),
                'body' => json_encode([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32601,
                        'message' => 'Unhandled MCP request',
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'protocol_version' => $protocolVersion,
                'session_id' => '',
                'messages' => [],
                'commands' => [],
            ];
        }

        if (!is_array($result)) {
            throw new RuntimeException('Invalid mcp result type');
        }

        $headers = isset($result['headers']) && is_array($result['headers'])
            ? $this->normalizeHeaderMap($result['headers'])
            : [];
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = 'application/json; charset=utf-8';
        }

        return [
            'mode' => 'mcp',
            'event' => 'result',
            'id' => $id,
            'handled' => (bool) ($result['handled'] ?? true),
            'status' => (int) ($result['status'] ?? 200),
            'headers' => JsonShape::objectMap($headers),
            'body' => (string) ($result['body'] ?? ''),
            'protocol_version' => (string) ($result['protocol_version'] ?? $protocolVersion),
            'session_id' => (string) ($result['session_id'] ?? ''),
            'messages' => isset($result['messages']) && is_array($result['messages'])
                ? array_values(array_map('strval', $result['messages']))
                : [],
            'commands' => $this->normalizeGatewayCommands($result['commands'] ?? []),
        ];
    }

    /** @param array<string,mixed> $frame
     *  @return array<string,mixed>
     */
    private function normalizeWebSocketUpstreamDispatchResult(array $frame, mixed $result): array
    {
        $id = (string) ($frame['id'] ?? '');

        if ($result === null || $result === false) {
            return [
                'mode' => 'websocket_upstream',
                'event' => 'result',
                'id' => $id,
                'handled' => false,
                'commands' => [],
            ];
        }

        if (!is_array($result)) {
            throw new RuntimeException('Invalid websocket_upstream result type');
        }

        return [
            'mode' => 'websocket_upstream',
            'event' => 'result',
            'id' => $id,
            'handled' => (bool) ($result['handled'] ?? true),
            'commands' => $this->normalizeGatewayCommands($result['commands'] ?? []),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array<string,mixed>>
     */
    private function normalizeGatewayCommands(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $commands = [];
        foreach ($raw as $command) {
            if (is_array($command)) {
                $commands[] = $command;
                continue;
            }
            if ($command instanceof \JsonSerializable) {
                $normalized = $command->jsonSerialize();
                if (is_array($normalized)) {
                    $commands[] = $normalized;
                }
            }
        }

        return array_values($commands);
    }

    /** @param array<string,mixed> $frame */
    private function normalizeWebSocketHandlerResult(
        array $frame,
        mixed $result,
        CommandSink $conn,
    ): void {
        $event = (string) ($frame['event'] ?? '');

        if ($result === false && $event === 'open' && !$conn->closed()) {
            $conn->close(1008, 'Rejected', 403);
            return;
        }

        if (is_string($result) && $result !== '') {
            if ($event === 'open' && !$conn->accepted() && !$conn->closed()) {
                $conn->accept();
            }
            if (!$conn->closed()) {
                $conn->send($result);
            }
        }

        if ($event === 'open' && !$conn->accepted() && !$conn->closed()) {
            $conn->accept();
        }
    }

    private function isPsr15RequestHandler(mixed $value): bool
    {
        if (!is_object($value)) {
            return false;
        }
        return interface_exists("Psr\\Http\\Server\\RequestHandlerInterface")
            && is_a($value, "Psr\\Http\\Server\\RequestHandlerInterface");
    }

    private function isPsr15Middleware(mixed $value): bool
    {
        if (!is_object($value)) {
            return false;
        }
        return interface_exists("Psr\\Http\\Server\\MiddlewareInterface")
            && is_a($value, "Psr\\Http\\Server\\MiddlewareInterface");
    }

    /** @param array<string,mixed> $req */
    private function isStreamRequest(array $req): bool
    {
        $mode = (string) ($req['mode'] ?? '');
        $strategy = trim(strtolower((string) ($req['strategy'] ?? '')));
        return $mode === 'stream' && $strategy === 'dispatch';
    }

    /** @param array<string,mixed> $req */
    private function isMcpRequest(array $req): bool
    {
        $mode = (string) ($req['mode'] ?? '');
        return $mode === 'mcp';
    }

    /** @param array<string,mixed> $req */
    private function isWebSocketUpstreamRequest(array $req): bool
    {
        $mode = (string) ($req['mode'] ?? '');
        return $mode === 'websocket_upstream';
    }

    private function hasDispatchableKind(mixed $value, string ...$kinds): bool
    {
        if (!is_object($value)) {
            return false;
        }
        $needles = array_values(array_filter(array_map(
            static fn (string $kind): string => trim(strtolower($kind)),
            $kinds
        )));
        if ($needles === []) {
            return false;
        }
        foreach ($this->dispatchableKinds($value) as $kind) {
            if (in_array($kind, $needles, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function dispatchableKinds(object $value): array
    {
        $kinds = [];
        if ($value instanceof \VSlim\App) {
            $kinds[] = 'http';
        }
        try {
            $attributes = (new ReflectionClass($value))->getAttributes();
        } catch (\ReflectionException) {
            $attributes = [];
        }
        foreach ($attributes as $attribute) {
            if ($attribute->getName() !== 'VPhp\\VHttpd\\Attribute\\Dispatchable') {
                continue;
            }
            $arguments = $attribute->getArguments();
            $kind = '';
            if (isset($arguments['kind']) && is_string($arguments['kind'])) {
                $kind = trim(strtolower($arguments['kind']));
            } elseif (isset($arguments[0]) && is_string($arguments[0])) {
                $kind = trim(strtolower($arguments[0]));
            }
            if ($kind !== '' && !in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }
        return $kinds;
    }

    /** @return list<object> */
    private function normalizePsr15Middlewares(array $items): array
    {
        $middlewares = [];
        foreach ($items as $item) {
            if ($this->isPsr15Middleware($item)) {
                $middlewares[] = $item;
            }
        }
        return $middlewares;
    }

    private function buildPsr15DispatcherFromBootstrap(mixed $loaded): ?object
    {
        if (
            !interface_exists("Psr\\Http\\Server\\RequestHandlerInterface") ||
            !interface_exists("Psr\\Http\\Server\\MiddlewareInterface") ||
            !is_array($loaded)
        ) {
            return null;
        }

        $handler = null;
        $middlewaresRaw = [];
        if (array_key_exists("handler", $loaded)) {
            $handler = $loaded["handler"];
        }
        if (array_key_exists("middlewares", $loaded) && is_array($loaded["middlewares"])) {
            $middlewaresRaw = $loaded["middlewares"];
        } else {
            foreach ($loaded as $value) {
                if ($this->isPsr15Middleware($value)) {
                    $middlewaresRaw[] = $value;
                }
                if ($handler === null && $this->isPsr15RequestHandler($value)) {
                    $handler = $value;
                }
            }
        }

        if (!$this->isPsr15RequestHandler($handler)) {
            return null;
        }
        $middlewares = $this->normalizePsr15Middlewares($middlewaresRaw);
        if ($middlewares === []) {
            return null;
        }

        return $this->createPsr15Dispatcher($handler, $middlewares);
    }

    /** @param list<object> $middlewares */
    private function createPsr15Dispatcher(object $handler, array $middlewares): object
    {
        return new class ($handler, $middlewares) implements \Psr\Http\Server\RequestHandlerInterface {
            /** @param list<object> $middlewares */
            public function __construct(
                private object $handler,
                private array $middlewares,
            ) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->dispatch($request, 0);
            }

            public function dispatch(
                \Psr\Http\Message\ServerRequestInterface $request,
                int $index,
            ): \Psr\Http\Message\ResponseInterface {
                if ($index >= count($this->middlewares)) {
                    /** @var \Psr\Http\Server\RequestHandlerInterface $h */
                    $h = $this->handler;
                    return $h->handle($request);
                }

                /** @var \Psr\Http\Server\MiddlewareInterface $mw */
                $mw = $this->middlewares[$index];
                $next = new class ($this, $index + 1) implements \Psr\Http\Server\RequestHandlerInterface {
                    public function __construct(
                        private object $dispatcher,
                        private int $nextIndex,
                    ) {}

                    public function handle(
                        \Psr\Http\Message\ServerRequestInterface $request,
                    ): \Psr\Http\Message\ResponseInterface {
                        $dispatcher = $this->dispatcher;
                        return $dispatcher->dispatch($request, $this->nextIndex);
                    }
                };

                return $mw->process($request, $next);
            }
        };
    }

    private function normalizeStreamResponseObject(mixed $result): ?StreamResponse
    {
        if ($result instanceof StreamResponse) {
            return $result;
        }
        if (!is_object($result)) {
            return null;
        }
        if (
            !is_a($result, 'VSlim\\Stream\\Response')
            && !is_a($result, 'VPhp\\VSlim\\Stream\\Response')
        ) {
            return null;
        }

        $streamType = property_exists($result, 'stream_type')
            ? (string) $result->stream_type
            : ((method_exists($result, 'stream_type') ? (string) $result->stream_type() : 'text'));
        $status = property_exists($result, 'status') ? (int) $result->status : 200;
        $contentType = property_exists($result, 'content_type')
            ? (string) $result->content_type
            : ($streamType === 'sse' ? 'text/event-stream' : 'text/plain; charset=utf-8');
        $headers = method_exists($result, 'headers') ? (array) $result->headers() : [];
        $chunks = method_exists($result, 'chunks') ? $result->chunks() : [];
        if (is_callable($chunks)) {
            $chunks = $chunks();
        }
        if (!is_iterable($chunks)) {
            $chunks = [];
        }

        return new StreamResponse(
            $streamType === 'sse' ? 'sse' : 'text',
            $chunks,
            $status > 0 ? $status : 200,
            $contentType !== '' ? $contentType : ($streamType === 'sse' ? 'text/event-stream' : 'text/plain; charset=utf-8'),
            $headers,
        );
    }

    private function normalizeUpstreamPlanObject(mixed $result): ?Plan
    {
        if ($result instanceof Plan) {
            return $result;
        }
        if (!is_object($result) || !is_a($result, 'VPhp\\VHttpd\\Upstream\\Plan')) {
            return null;
        }
        /** @var Plan $result */
        return $result;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    private function readAssocMap(array $input, string $key): array
    {
        $raw = $input[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name => $value) {
            if (is_array($value)) {
                $out[(string) $name] = implode(", ", array_map("strval", $value));
            } else {
                $out[(string) $name] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $input
     * @return list<string>
     */
    private function readList(array $input, string $key): array
    {
        $raw = $input[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_map("strval", $raw));
    }

    private function resolveAppBootstrapPath(): ?string
    {
        // Stable v1 runtime contract: vhttpd injects the worker app bootstrap via VHTTPD_APP.
        $env = getenv("VHTTPD_APP");
        if (is_string($env) && $env !== "") {
            return $env;
        }
        if (
            $this->appBootstrapPath !== null &&
            $this->appBootstrapPath !== ""
        ) {
            return $this->appBootstrapPath;
        }
        return __DIR__ . "/app.php";
    }

    /**
     * @param mixed $result
     * @return array<string,mixed>
     */
    private function normalizeAppResponse(string $id, mixed $result): array
    {
        if (is_array($result)) {
            $headers = $this->normalizeHeaderMap(
                isset($result["headers"]) && is_array($result["headers"])
                    ? $result["headers"]
                    : [],
            );
            $body = (string) ($result["body"] ?? "");
            $contentType =
                (string) ($result["content_type"] ??
                    ($headers["content-type"] ?? "text/plain; charset=utf-8"));
            return $this->buildNormalizedResponse(
                $id,
                (int) ($result["status"] ?? 200),
                $body,
                $headers,
                $contentType,
            );
        }

        if (is_string($result)) {
            return $this->res($id, 200, $result);
        }

        if (is_object($result) && $result instanceof \VSlim\App) {
            return $this->res($id, 500, "Internal Server Error", [
                "x-worker-error" => "App bootstrap returned VSlim\\\\App instance as response",
                "x-worker-error-class" => "app_contract_error",
            ]);
        }

        if (is_object($result) && $result instanceof \VSlim\Response) {
            try {
                $headers = [];
                if (function_exists("vslim_response_headers")) {
                    $headers = $this->normalizeHeaderMap((array) vslim_response_headers($result));
                }
                $body = (string) ($result->body ?? "");
                $contentType =
                    (string) ($result->content_type ??
                        ($headers["content-type"] ?? "text/plain; charset=utf-8"));
                return $this->buildNormalizedResponse(
                    $id,
                    (int) ($result->status ?? 200),
                    $body,
                    $headers,
                    $contentType,
                );
            } catch (Throwable $e) {
                return $this->res($id, 500, "Internal Server Error", [
                    "x-worker-error" => "Invalid VSlim response object: " . $e->getMessage(),
                    "x-worker-error-class" => "app_contract_error",
                    "x-worker-exception" => get_class($e),
                ]);
            }
        }

        if (
            is_object($result) &&
            method_exists($result, "getStatusCode") &&
            method_exists($result, "getHeaders") &&
            method_exists($result, "getBody")
        ) {
            $status = (int) $result->getStatusCode();
            $headers = $this->normalizeHeaderMap((array) $result->getHeaders());
            $body = $this->stringifyBody($result->getBody());
            $contentType =
                (string) ($headers["content-type"] ??
                    "text/plain; charset=utf-8");
            return $this->buildNormalizedResponse(
                $id,
                $status,
                $body,
                $headers,
                $contentType,
            );
        }

        return $this->res($id, 500, "Internal Server Error", [
            "x-worker-error" => "Unsupported app response type",
            "x-worker-error-class" => "app_contract_error",
        ]);
    }

    /**
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    private function normalizeHeaderMap(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            if (is_array($value)) {
                $out[$key] = implode(", ", array_map("strval", $value));
            } else {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }

    private function classifyThrowable(Throwable $e): string
    {
        if (
            $e instanceof TypeError
            || $e instanceof InvalidArgumentException
            || $e instanceof LogicException
        ) {
            return "app_contract_error";
        }
        return "worker_runtime_error";
    }

    private function stringifyBody(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        if (is_object($body)) {
            if (method_exists($body, "rewind")) {
                try {
                    $body->rewind();
                } catch (Throwable) {
                }
            }
            if (method_exists($body, "getContents")) {
                return (string) $body->getContents();
            }
            if (method_exists($body, "__toString")) {
                return (string) $body;
            }
        }
        return (string) $body;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function buildNormalizedResponse(
        string $id,
        int $status,
        string $body,
        array $headers,
        string $contentType,
    ): array {
        if (!isset($headers["content-type"])) {
            $headers["content-type"] = $contentType;
        }
        if (!isset($headers["content-length"])) {
            $headers["content-length"] = (string) strlen($body);
        }
        return [
            "id" => $id,
            "status" => $status,
            "content_type" => $headers["content-type"],
            "headers" => $headers,
            "body" => $body,
        ];
    }

    /** @param array<string,mixed> $query */
    private function rebuildPath(string $path, array $query): string
    {
        if ($query === [] || str_contains($path, "?")) {
            return $path;
        }
        return $path . "?" . http_build_query($query);
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function res(
        string $id,
        int $status,
        string $body,
        array $headers = [],
    ): array {
        return [
            "id" => $id,
            "status" => $status,
            "headers" => $headers + [
                "content-type" => "text/plain; charset=utf-8",
            ],
            "body" => $body,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function resJson(string $id, int $status, array $data): array
    {
        return [
            "id" => $id,
            "status" => $status,
            "headers" => ["content-type" => "application/json; charset=utf-8"],
            "body" => json_encode($data, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @param resource $conn */
    private function readFrame($conn): ?string
    {
        $header = $this->readExactly($conn, 4);
        if ($header === null) {
            return null;
        }
        $len = unpack("Nlen", $header);
        $size = (int) ($len["len"] ?? 0);
        if ($size <= 0 || $size > 16 * 1024 * 1024) {
            return null;
        }
        return $this->readExactly($conn, $size);
    }

    /** @param resource $conn */
    private function writeFrame($conn, string $payload): void
    {
        $header = pack("N", strlen($payload));
        fwrite($conn, $header . $payload);
    }

    /** @param resource $conn */
    private function readExactly($conn, int $len): ?string
    {
        $buf = "";
        while (strlen($buf) < $len) {
            $chunk = fread($conn, $len - strlen($buf));
            if ($chunk === "" || $chunk === false) {
                return null;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function prepareSocketPath(): void
    {
        @mkdir(dirname($this->socketPath), 0777, true);
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }
}

function parseSocketFromArgv(array $argv): string
{
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === "--socket" && isset($argv[$i + 1])) {
            return (string) $argv[$i + 1];
        }
        if (str_starts_with((string) $argv[$i], "--socket=")) {
            return substr((string) $argv[$i], 9);
        }
    }
    return "/tmp/vphp_worker.sock";
}
