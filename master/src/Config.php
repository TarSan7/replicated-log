<?php
declare(strict_types=1);

namespace Master;

final class Config
{
    /** @param list<string> $secondaries host:port of every secondary */
    public function __construct(
        public readonly string $nodeName,
        public readonly int $port,
        public readonly array $secondaries,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            nodeName: getenv('NODE_NAME') ?: 'master',
            port: (int)(getenv('PORT') ?: 8080),
            secondaries: array_values(array_filter(explode(',', getenv('SECONDARIES') ?: ''))),
        );
    }
}
