<?php
declare(strict_types=1);

use Master\Config;
use Master\Http\Routes;
use Master\Logger;
use Master\Service\RetryService;
use Master\Worker\HealthMonitor;
use Swoole\Http\Server;
use Swoole\Timer;

require __DIR__ . '/vendor/autoload.php';

$config = Config::fromEnv();
$logger = new Logger($config->nodeName);
$retryService = new RetryService($config->retryBaseDelay, $config->retryMaxDelay, $logger);
$healthMonitor = new HealthMonitor($config->secondaries, $logger);
$router = Routes::build($config, $logger, $retryService, $healthMonitor);

$server = new Server('0.0.0.0', $config->port, SWOOLE_BASE);
$server->set(['worker_num' => 1]);
$server->on('request', $router->dispatch(...));
$server->on('workerStart', function () use ($healthMonitor, $config) {
    Timer::tick($config->healthIntervalMs, fn () => $healthMonitor->checkAll()); // every 1000 ms
});

$logger->info('starting master', ['port' => $config->port, 'secondaries' => $config->secondaries]);
$server->start();
