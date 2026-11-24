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
        public readonly float $retryBaseDelay,
        public readonly float $retryMaxDelay,
        public readonly float $replicationTimeout,
        public readonly int $healthIntervalMs,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            nodeName: getenv('NODE_NAME') ?: 'master',
            port: (int)(getenv('PORT') ?: 8080),
            secondaries: array_values(array_filter(explode(',', getenv('SECONDARIES') ?: ''))),
            retryBaseDelay: (float)(getenv('RETRY_BASE_DELAY') ?: 0.5),
            retryMaxDelay: (float)(getenv('RETRY_MAX_DELAY') ?: 10),
            replicationTimeout: (float)(getenv('REPLICATION_TIMEOUT') ?: 5),
            healthIntervalMs: (int)(getenv('HEALTH_INTERVAL_MS') ?: 1000),
        );
    }
}
