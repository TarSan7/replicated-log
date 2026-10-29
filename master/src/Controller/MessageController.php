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
        $writeConcern = $body['w'] ?? null;

        if (!is_string($body['message'] ?? null)) {
            return new JsonResponse(['error' => 'message is required'], 400);
        }

        if (!is_int($writeConcern) || $writeConcern < 1 || $writeConcern > $this->replicator->countSecondaries() + 1) {
            return new JsonResponse(['error' => 'w must be an integer between 1 and ' . ($this->replicator->countSecondaries() + 1)], 400);
        }
        $requiredAcks = $writeConcern - 1;
        $entry = ['id' => $this->log->nextId(), 'message' => $body['message']];
        $this->log->append($entry);
        $this->logger->info('message appended', $entry);

        $acks = $this->replicator->replicate($entry, $writeConcern);

        if ($acks < $requiredAcks) {
            $this->logger->warning('replication failed, write concern not satisfied', ['id' => $entry['id'], 'acks' => $acks, 'required' => $requiredAcks]);

            return new JsonResponse(['error' => 'replication failed, write concern not satisfied', 'acks' => $acks, 'required' => $requiredAcks], 500);
        }
        $this->logger->info('message saved', ['id' => $entry['id'], 'w' => $writeConcern]);

        return new JsonResponse($entry, 201);
    }
}
