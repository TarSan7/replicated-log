<?php
declare(strict_types=1);

namespace Tests\Support;

use Master\Logger;
use Master\Replication\Replicator;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class Transport extends Replicator
{
    private array $nodes = [];
    private array $delays = [];
    private array $reorder = [];
    private array $held = [];
    private array $delivered = [];

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

    public function reorderMessages(MasterNode $from, SecondaryNode $to, int $count = 2): void
    {
        $this->reorder[$to->name] = $count;
    }

    public function deliveredTo(SecondaryNode $node): array
    {
        return $this->delivered[$node->name] ?? [];
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

        return isset($this->reorder[$host])
            ? $this->holdForReorder($host, $entry)
            : $this->deliver($host, $entry);
    }

    private function holdForReorder(string $host, array $entry): bool
    {
        $ack = new Channel(1);
        $this->held[$host][] = [$entry, $ack];

        if (count($this->held[$host]) === $this->reorder[$host]) {
            $batch = array_reverse($this->held[$host]);
            unset($this->held[$host], $this->reorder[$host]);

            foreach ($batch as [$heldEntry, $heldAck]) {
                $heldAck->push($this->deliver($host, $heldEntry));
            }
        }

        return $ack->pop();
    }

    private function deliver(string $host, array $entry): bool
    {
        $ok = $this->nodes[$host]->replicate($entry);
        $this->delivered[$host][] = $entry['id'];

        return $ok;
    }
}
