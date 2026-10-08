<?php
declare(strict_types=1);

namespace Secondary\Http;

use Secondary\Config;
use Secondary\Controller\MessageController;
use Secondary\Controller\ReplicationController;
use Secondary\Logger;
use Secondary\Storage\MessageLog;

final class Routes
{
    private readonly MessageController $messages;
    private readonly ReplicationController $replication;

    public function __construct(Config $config, private readonly Logger $logger)
    {
        $log               = new MessageLog();
        $this->messages    = new MessageController($log);
        $this->replication = new ReplicationController($log, $logger, $config->delayMs);
    }

    /** Endpoints for clients, served on PORT. */
    public function public(): Router
    {
        return (new Router($this->logger))
            ->get('/messages', $this->messages->index(...));
    }

    /** Endpoints for the master only, served on INTERNAL_PORT. */
    public function internal(): Router
    {
        return (new Router($this->logger))
            ->post('/replicate', $this->replication->replicate(...));
    }
}
