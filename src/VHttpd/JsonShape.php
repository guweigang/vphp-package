<?php

declare(strict_types=1);

namespace VPhp\VHttpd;

final class JsonShape
{
    private function __construct()
    {
    }

    /** @param array<mixed> $value */
    public static function objectMap(array $value): object|array
    {
        return $value === [] ? (object) [] : $value;
    }
}
