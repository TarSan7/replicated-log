<?php
declare(strict_types=1);

namespace Master\Service;

use Master\Logger;
use Master\Worker\HealthMonitor;
use Swoole\Coroutine;

class RetryService
{
    private const UNHEALTHY_POLL_INTERVAL = 1;

    public function __construct(
        private readonly float $baseDelay,
        private readonly float $maxDelay,
        private readonly Logger $logger,
    ) {
    }

    public function runWithRetry(callable $function, array $context = [], ?callable $status = null): void
    {
        $attempt = 1;

        while (true) {
            if ($status !== null && $status() === HealthMonitor::UNHEALTHY) {
                $this->waitUntilAvailable($status, $context);
                $attempt = 1;
            }

            try {
                if ($function()) {
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('attempt failed with exception', $context + [
                    'attempt' => $attempt,
                    'exception' => $e->getMessage(),
                ]);
            }

            $delay = $this->delay($attempt);
            if ($status !== null && $status() === HealthMonitor::SUSPECTED) {
                $delay = min($delay * 2, $this->maxDelay);
            }
            $this->logger->info('retrying', $context + ['attempt' => $attempt, 'delay_s' => round($delay, 2)]);
            Coroutine::sleep($delay);
            $attempt++;
        }
    }

    private function waitUntilAvailable(callable $status, array $context): void
    {
        $this->logger->info('target unhealthy, pausing retries', $context);

        while ($status() === HealthMonitor::UNHEALTHY) {
            Coroutine::sleep(self::UNHEALTHY_POLL_INTERVAL);
        }
        $this->logger->info('target available again, resuming retries', $context);
    }

    private function delay(int $attempt): float
    {
        $delay = min($this->baseDelay * 2 ** ($attempt - 1), $this->maxDelay);

        return $delay * (0.8 + 0.4 * mt_rand() / mt_getrandmax());
    }
}
