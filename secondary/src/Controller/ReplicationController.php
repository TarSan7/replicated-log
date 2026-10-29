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

        if (!$this->isValid($entry)) {
            return new JsonResponse(['error' => 'id must be a positive int and message must be a string'], 400);
        }
        $this->logger->info('replication received', ['id' => $entry['id']]);

        if ($this->delayMs > 0) {
            $this->logger->info('delaying ack', ['id' => $entry['id'], 'ms' => $this->delayMs]);
            Coroutine::sleep($this->delayMs / 1000);
        }
        $result = $this->log->append($entry);
        $this->logger->info("message {$result['status']}", ['id' => $entry['id']] + $result);

        return new JsonResponse(['ack' => true] + $result);
    }

    private function isValid(mixed $entry): bool
    {
        return is_array($entry)
            && is_int($entry['id'] ?? null)
            && is_string($entry['message'] ?? null)
            && $entry['id'] > 0;
    }
}
