<?php
declare(strict_types=1);

namespace Tests\Support;

use Master\Logger;
use Master\Replication\Replicator;
use Swoole\Http\Request;
use Swoole\Coroutine;

final class Transport extends Replicator
{
    private array $nodes = [];
    private array $delays = [];

    public function __construct()
    {
        parent::__construct([], new Logger('transport'));
    }

    public function connect(SecondaryNode $node): void
    {
        $this->nodes[$node->name] = $node;
    }

    public function setDelay(MasterNode $from, SecondaryNode $to, float $seconds): void
    {
        $this->delays[$to->name] = $seconds;
    }

    protected function secondaries(): array
    {
        return array_keys($this->nodes);
    }

    protected function send(string $host, array $entry): bool
    {
        $delay = $this->delays[$host] ?? 0;
        if ($delay > 0) {
            Coroutine::sleep($delay);
        }
        return $this->nodes[$host]->replicate($entry);
    }
}
