<?php
declare(strict_types=1);

namespace Master\Controller;

use Master\Http\JsonResponse;
use Master\Worker\HealthMonitor;
use Swoole\Http\Request;

final class HealthController
{
    public function __construct(private readonly HealthMonitor $healthMonitor)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'mode' => $this->healthMonitor->hasQuorum() ? 'read-write' : 'read-only',
            'alive_nodes' => $this->healthMonitor->aliveNodes(),
            'quorum' => $this->healthMonitor->quorumSize(),
            'secondaries' => $this->healthMonitor->all(),
        ]);
    }
}
