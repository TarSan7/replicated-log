<?php
declare(strict_types=1);

namespace Master\Replication;

use Master\Logger;
use Master\Service\RetryService;
use Master\Worker\HealthMonitor;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Channel;

class Replicator
{
    /** @param list<string> $secondaries host:port of every secondary */
    public function __construct(
        private readonly array $secondaries,
        private readonly Logger $logger,
        private readonly RetryService $retryService,
        private readonly HealthMonitor $healthMonitor,
        private readonly float $timeout = 5,
    ) {
    }

    /**
     * Sends the entry to all secondaries in parallel, retrying each one until it ACKs.
     * Blocks until w-1 secondaries have ACKed; delivery to the rest continues in the background.
     *
     * @return int number of ACK
     */
    public function replicate(array $entry, int $w): int
    {
        $acks = 0;
        $results = new Channel(count($this->secondaries()));

        foreach ($this->secondaries() as $host) {
            Coroutine::create(function () use ($host, $entry, $results): void {
                $this->retryService->runWithRetry(
                    fn () => $this->send($host, $entry),
                    ['id' => $entry['id'], 'to' => $host],
                    fn () => $this->healthMonitor->status($host),
                );
                $results->push($host);
            });
        }

        while ($acks < $w - 1) {
            $results->pop();
            $acks++;
        }

        return $acks;
    }

    public function countSecondaries(): int
    {
        return count($this->secondaries());
    }

    /** @return list<string> */
    protected function secondaries(): array
    {
        return $this->secondaries;
    }

    protected function send(string $host, array $entry): bool
    {
        $start = hrtime(true);
        $this->logger->info('replicating', ['id' => $entry['id'], 'to' => $host]);

        [$h, $p] = explode(':', $host);
        $client = new Client($h, (int)$p);
        $client->set(['timeout' => $this->timeout]);
        $client->setHeaders(['content-type' => 'application/json']);
        $client->post('/replicate', json_encode($entry));
        $ok = $client->statusCode === 200;

        $context = ['id' => $entry['id'], 'from' => $host, 'ms' => round((hrtime(true) - $start) / 1e6, 1)];
        $ok
            ? $this->logger->info('ack received', $context)
            : $this->logger->warning('no ack', $context + ['status' => $client->statusCode, 'error' => $client->errMsg]);

        $client->close();

        return $ok;
    }
}
