<?php
declare(strict_types=1);

namespace Secondary;

final class Config
{
    public function __construct(
        public readonly string $nodeName,
        public readonly int $port,
        public readonly string $internalHost,
        public readonly int $internalPort,
        public readonly int $delayMs,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            nodeName: getenv('NODE_NAME') ?: 'secondary',
            port: (int)(getenv('PORT') ?: 8080),
            internalHost: getenv('INTERNAL_HOST') ?: '0.0.0.0',
            internalPort: (int)(getenv('INTERNAL_PORT') ?: 8081),
            delayMs: (int)(getenv('DELAY_MS') ?: 0),
        );
    }
}
