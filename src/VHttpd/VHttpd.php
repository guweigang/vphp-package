<?php

declare(strict_types=1);

namespace VPhp\VHttpd;

use RuntimeException;

final class VHttpd
{
    public static function admin(): AdminClient
    {
        $socketPath = trim((string) getenv('VHTTPD_INTERNAL_ADMIN_SOCKET'));
        if ($socketPath === '') {
            throw new RuntimeException('VHTTPD_INTERNAL_ADMIN_SOCKET is not configured');
        }
        return new AdminClient($socketPath);
    }

    public static function gateway(string $provider): GatewayClient
    {
        $socketPath = trim((string) getenv('VHTTPD_INTERNAL_ADMIN_SOCKET'));
        if ($socketPath === '') {
            throw new RuntimeException('VHTTPD_INTERNAL_ADMIN_SOCKET is not configured');
        }
        return new GatewayClient($socketPath, trim($provider) ?: 'feishu');
    }
}
