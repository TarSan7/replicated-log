<?php
declare(strict_types=1);

use Secondary\Config;
use Secondary\Http\Routes;
use Secondary\Logger;
use Swoole\Http\Server;

require __DIR__ . '/vendor/autoload.php';

$config = Config::fromEnv();
$logger = new Logger($config->nodeName);
$routes = new Routes($config, $logger);

$server = new Server('0.0.0.0', $config->port, SWOOLE_BASE);
$server->set(['worker_num' => 1]);
$server->on('request', $routes->public()->dispatch(...));

$internal = $server->listen($config->internalHost, $config->internalPort, SWOOLE_SOCK_TCP);
$internal->on('request', $routes->internal()->dispatch(...));

$logger->info('starting secondary', [
    'port'     => $config->port,
    'internal' => "{$config->internalHost}:{$config->internalPort}",
    'delay_ms' => $config->delayMs,
]);
$server->start();
