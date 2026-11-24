<?php
declare(strict_types=1);

namespace Tests\Support;

use Master\Logger;
use Master\Worker\HealthMonitor;

final class AlwaysHealthyMonitor extends HealthMonitor
{
    public function __construct()
    {
        parent::__construct([], new Logger('health'));
    }

    public function status(string $host): string
    {
        return self::HEALTHY;
    }
}
