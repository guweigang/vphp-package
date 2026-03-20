<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Upstream\WebSocket\Feishu;

use VPhp\VSlim\Mcp\App;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Content\CardHeader;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Content\CardMarkdown;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Content\InteractiveCard;
use VPhp\VHttpd\Upstream\WebSocket\Feishu\Content\PlainText;
use VPhp\VHttpd\VHttpd;

final class McpToolset
{
    public static function register(App $mcp): App
    {
        return $mcp
            ->tool(
                'feishu.list_chats',
                'List Feishu chats learned by vhttpd from recent inbound events',
                [
                    'type' => 'object',
                    'properties' => [
                        'instance' => ['type' => 'string'],
                        'chat_type' => ['type' => 'string'],
                    ],
                ],
                static function (array $arguments): array {
                    $instance = trim((string) ($arguments['instance'] ?? ''));
                    $chatType = trim((string) ($arguments['chat_type'] ?? ''));
                    try {
                        $response = VHttpd::admin()->get('/runtime/feishu/chats', array_filter([
                            'instance' => $instance,
                            'chat_type' => $chatType,
                        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));
                    } catch (\Throwable $e) {
                        return [
                            'content' => [[
                                'type' => 'text',
                                'text' => 'failed to query vhttpd feishu chats: ' . $e->getMessage(),
                            ]],
                            'isError' => true,
                        ];
                    }
                    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
                    $rows = is_array($body['chats'] ?? null) ? $body['chats'] : [];
                    if (($response['status'] ?? 0) !== 200) {
                        $errorText = trim((string) ($response['error'] ?? ''));
                        if ($errorText === '' && is_string($body['error'] ?? null)) {
                            $errorText = trim((string) $body['error']);
                        }
                        $detailParts = [
                            'status=' . (string) ($response['status'] ?? 0),
                        ];
                        if ($errorText !== '') {
                            $detailParts[] = 'error=' . $errorText;
                        }
                        $rawBody = trim((string) ($response['raw_body'] ?? ''));
                        if ($rawBody !== '' && $rawBody !== '{"error":"' . $errorText . '"}') {
                            $detailParts[] = 'body=' . $rawBody;
                        }
                        return [
                            'content' => [[
                                'type' => 'text',
                                'text' => 'failed to query feishu chats (' . implode(', ', $detailParts) . ')',
                            ]],
                            'status' => $response['status'] ?? 500,
                            'isError' => true,
                            'chats' => $rows,
                            'count' => count($rows),
                        ];
                    }
                    return [
                        'chats' => $rows,
                        'count' => count($rows),
                        'content' => [[
                            'type' => 'text',
                            'text' => 'found ' . count($rows) . ' feishu chat(s)',
                        ]],
                        'isError' => false,
                    ];
                },
            )
            ->tool(
                'feishu.send_text',
                'Send a text message through the configured Feishu gateway instance',
                [
                    'type' => 'object',
                    'properties' => [
                        'chat_id' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                        'instance' => ['type' => 'string'],
                    ],
                    'required' => ['chat_id', 'text'],
                ],
                static function (array $arguments): array {
                    $instance = trim((string) ($arguments['instance'] ?? ''));
                    return [
                        'content' => [[
                            'type' => 'text',
                            'text' => 'queued feishu text message',
                        ]],
                        'isError' => false,
                        'commands' => [
                            Command::sendTextTo(
                                'chat_id',
                                trim((string) ($arguments['chat_id'] ?? '')),
                                (string) ($arguments['text'] ?? ''),
                                $instance !== '' ? $instance : 'main',
                            ),
                        ],
                    ];
                },
            )
            ->tool(
                'feishu.send_image',
                'Send an image message through the configured Feishu gateway instance',
                [
                    'type' => 'object',
                    'properties' => [
                        'chat_id' => ['type' => 'string'],
                        'image_key' => ['type' => 'string'],
                        'instance' => ['type' => 'string'],
                    ],
                    'required' => ['chat_id', 'image_key'],
                ],
                static function (array $arguments): array {
                    $instance = trim((string) ($arguments['instance'] ?? ''));
                    return [
                        'content' => [[
                            'type' => 'text',
                            'text' => 'queued feishu image message',
                        ]],
                        'isError' => false,
                        'commands' => [[
                            'event' => 'send',
                            'provider' => 'feishu',
                            'instance' => $instance !== '' ? $instance : 'main',
                            'target_type' => 'chat_id',
                            'target' => trim((string) ($arguments['chat_id'] ?? '')),
                            'message_type' => 'image',
                            'content_fields' => [
                                'image_key' => trim((string) ($arguments['image_key'] ?? '')),
                            ],
                        ]],
                    ];
                },
            )
            ->tool(
                'feishu.send_card',
                'Send a simple interactive card through the configured Feishu gateway instance',
                [
                    'type' => 'object',
                    'properties' => [
                        'chat_id' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'markdown' => ['type' => 'string'],
                        'instance' => ['type' => 'string'],
                    ],
                    'required' => ['chat_id', 'title', 'markdown'],
                ],
                static function (array $arguments): array {
                    $instance = trim((string) ($arguments['instance'] ?? ''));
                    $card = InteractiveCard::create((string) ($arguments['title'] ?? 'card'))
                        ->wideScreen()
                        ->header(CardHeader::create(PlainText::create((string) ($arguments['title'] ?? 'card'))))
                        ->element(CardMarkdown::create((string) ($arguments['markdown'] ?? '')));
                    return [
                        'content' => [[
                            'type' => 'text',
                            'text' => 'queued feishu interactive card',
                        ]],
                        'isError' => false,
                        'commands' => [[
                            'event' => 'send',
                            'provider' => 'feishu',
                            'instance' => $instance !== '' ? $instance : 'main',
                            'target_type' => 'chat_id',
                            'target' => trim((string) ($arguments['chat_id'] ?? '')),
                            'message_type' => 'interactive',
                            'content' => json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]],
                    ];
                },
            )
            ->tool(
                'feishu.update_card',
                'Update an existing interactive card message by message_id',
                [
                    'type' => 'object',
                    'properties' => [
                        'message_id' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'markdown' => ['type' => 'string'],
                        'instance' => ['type' => 'string'],
                    ],
                    'required' => ['message_id', 'title', 'markdown'],
                ],
                static function (array $arguments): array {
                    $instance = trim((string) ($arguments['instance'] ?? ''));
                    $card = InteractiveCard::create((string) ($arguments['title'] ?? 'updated'))
                        ->wideScreen()
                        ->header(CardHeader::create(PlainText::create((string) ($arguments['title'] ?? 'updated'))))
                        ->element(CardMarkdown::create((string) ($arguments['markdown'] ?? '')));
                    return [
                        'content' => [[
                            'type' => 'text',
                            'text' => 'queued feishu interactive card update',
                        ]],
                        'isError' => false,
                        'commands' => [[
                            'event' => 'update',
                            'provider' => 'feishu',
                            'instance' => $instance !== '' ? $instance : 'main',
                            'target_type' => 'message_id',
                            'target' => trim((string) ($arguments['message_id'] ?? '')),
                            'message_type' => 'interactive',
                            'content' => json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]],
                    ];
                },
            );
    }
}
