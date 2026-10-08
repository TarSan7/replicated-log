<?php
declare(strict_types=1);

use Master\Config;
use Master\Http\Routes;
use Master\Logger;
use Swoole\Http\Server;

require __DIR__ . '/vendor/autoload.php';

$config = Config::fromEnv();
$logger = new Logger($config->nodeName);
$router = Routes::build($config, $logger);

$server = new Server('0.0.0.0', $config->port, SWOOLE_BASE);
$server->set(['worker_num' => 1]);
$server->on('request', $router->dispatch(...));

$logger->info('starting master', ['port' => $config->port, 'secondaries' => $config->secondaries]);
$server->start();
