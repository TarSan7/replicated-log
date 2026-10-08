<?php
declare(strict_types=1);

namespace Master\Controller;

use Master\Http\Json;
use Master\Http\JsonResponse;
use Master\Logger;
use Master\Replication\Replicator;
use Master\Storage\MessageLog;
use Swoole\Http\Request;

final class MessageController
{
    public function __construct(
        private readonly MessageLog $log,
        private readonly Replicator $replicator,
        private readonly Logger $logger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse($this->log->messages());
    }

    public function store(Request $request): JsonResponse
    {
        $body = Json::body($request);
        if (!is_string($body['message'] ?? null)) {
            return new JsonResponse(['error' => 'message is required'], 400);
        }

        $entry = ['id' => $this->log->nextId(), 'message' => $body['message']];
        $this->log->append($entry);
        $this->logger->info('message appended', $entry);

        $failed = $this->replicator->replicate($entry);
        if ($failed) {
            $this->logger->warning('replication failed', ['id' => $entry['id'], 'hosts' => $failed]);
            return new JsonResponse(['error' => 'replication failed', 'hosts' => $failed], 500);
        }

        $this->logger->info('message replicated to all secondaries', ['id' => $entry['id']]);
        return new JsonResponse($entry, 201);
    }
}
