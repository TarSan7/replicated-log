<?php
declare(strict_types=1);

namespace Master\Http;

use Master\Config;
use Master\Controller\MessageController;
use Master\Logger;
use Master\Replication\Replicator;
use Master\Storage\MessageLog;

final class Routes
{
    public static function build(Config $config, Logger $logger): Router
    {
        $messages = new MessageController(
            new MessageLog(),
            new Replicator($config->secondaries, $logger),
            $logger,
        );

        return (new Router($logger))
            ->get('/messages', $messages->index(...))
            ->post('/messages', $messages->store(...));
    }
}
