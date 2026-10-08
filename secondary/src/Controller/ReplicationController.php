<?php
declare(strict_types=1);

namespace Secondary\Controller;

use Secondary\Http\Json;
use Secondary\Http\JsonResponse;
use Secondary\Logger;
use Secondary\Storage\MessageLog;
use Swoole\Coroutine;
use Swoole\Http\Request;

final class ReplicationController
{
    public function __construct(
        private readonly MessageLog $log,
        private readonly Logger $logger,
        private readonly int $delayMs,
    ) {
    }

    public function replicate(Request $request): JsonResponse
    {
        $entry = Json::body($request);
        $this->logger->info('replication received', ['id' => $entry['id'] ?? null]);

        if ($this->delayMs > 0) {
            $this->logger->info('delaying ack', ['id' => $entry['id'] ?? null, 'ms' => $this->delayMs]);
            Coroutine::sleep($this->delayMs / 1000);
        }

        $this->log->append($entry);
        $this->logger->info('message stored', $entry);

        return new JsonResponse(['ack' => true]);
    }
}
