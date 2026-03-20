<?php

declare(strict_types=1);

namespace VPhp\VHttpd\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Dispatchable
{
    public function __construct(
        public string $kind,
    ) {
    }
}
