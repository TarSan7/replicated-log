<?php
declare(strict_types=1);

namespace Tests\Support;

use Master\Logger;
use Master\Replication\Replicator;
use Master\Service\RetryService;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class Transport extends Replicator
{
    private array $nodes = [];
    private array $delays = [];
    private array $drops = [];
    private array $reorder = [];
    private array $held = [];
    private array $delivered = [];
    private array $attempts = [];

    /**
     * @param float $timeout        how long the master waits for an ACK before the attempt counts as failed
     * @param float $retryBaseDelay delay before the first retry, doubled on each attempt
     * @param float $retryMaxDelay  cap for the delay between retries
     */
    public function __construct(
        private readonly float $timeout = 60,
        float $retryBaseDelay = 0.1,
        float $retryMaxDelay = 1,
    ) {
        $logger = new Logger('transport');
        parent::__construct([], $logger, new RetryService($retryBaseDelay, $retryMaxDelay, $logger), new AlwaysHealthyMonitor(), $timeout);
    }

    public function connect(SecondaryNode $node): void
    {
        $this->nodes[$node->name] = $node;
    }

    public function setDelay(MasterNode $from, SecondaryNode $to, float $seconds): void
    {
        $this->delays[$to->name] = $seconds;
    }

    public function removeDelay(MasterNode $from, SecondaryNode $to): void
    {
        unset($this->delays[$to->name]);
    }

    /** Messages to $to are lost until removeDrop(): every attempt fails and nothing reaches the secondary. */
    public function dropMessage(MasterNode $from, SecondaryNode $to): void
    {
        $this->drops[$to->name] = true;
    }

    public function removeDrop(MasterNode $from, SecondaryNode $to): void
    {
        unset($this->drops[$to->name]);
    }

    public function reorderMessages(MasterNode $from, SecondaryNode $to, int $count = 2): void
    {
        $this->reorder[$to->name] = $count;
    }

    /** Ids that reached the secondary, in arrival order, duplicates included. */
    public function deliveredTo(SecondaryNode $node): array
    {
        return $this->delivered[$node->name] ?? [];
    }

    /** How many times the master tried to send message $id to $node. */
    public function attempts(SecondaryNode $node, int $id): int
    {
        return $this->attempts[$node->name][$id] ?? 0;
    }

    protected function secondaries(): array
    {
        return array_keys($this->nodes);
    }

    protected function send(string $host, array $entry): bool
    {
        $this->attempts[$host][$entry['id']] = $this->attempts($this->nodes[$host], $entry['id']) + 1;

        if (isset($this->drops[$host])) {
            return false;
        }

        $delay = $this->delays[$host] ?? 0;
        if ($delay > 0) {
            // The request is in flight for $delay seconds; the master stops waiting after $timeout,
            // but the request still reaches the secondary later, as a slow network would deliver it.
            $ack = new Channel(1);
            Coroutine::create(function () use ($host, $entry, $delay, $ack): void {
                Coroutine::sleep($delay);
                $ack->push($this->dispatch($host, $entry));
            });

            return $ack->pop($this->timeout) === true;
        }

        return $this->dispatch($host, $entry);
    }

    private function dispatch(string $host, array $entry): bool
    {
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
