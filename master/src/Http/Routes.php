<?php
declare(strict_types=1);

namespace Master\Http;

use Master\Config;
use Master\Controller\HealthController;
use Master\Controller\MessageController;
use Master\Logger;
use Master\Replication\Replicator;
use Master\Service\RetryService;
use Master\Storage\MessageLog;
use Master\Worker\HealthMonitor;

final class Routes
{
    public static function build(Config $config, Logger $logger, RetryService $retryService, HealthMonitor $healthMonitor): Router
    {
        $messages = new MessageController(
            new MessageLog(),
            new Replicator($config->secondaries, $logger, $retryService, $healthMonitor, $config->replicationTimeout),
            $logger,
            $healthMonitor,
        );
        $health = new HealthController($healthMonitor);

        return (new Router($logger))
            ->get('/messages', $messages->index(...))
            ->post('/messages', $messages->store(...))
            ->get('/health', $health->index(...));
    }
}
