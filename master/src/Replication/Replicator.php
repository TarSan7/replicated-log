<?php
declare(strict_types=1);

namespace Master\Replication;

use Master\Logger;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\WaitGroup;

class Replicator
{
    /** @param list<string> $secondaries host:port of every secondary */
    public function __construct(
        private readonly array $secondaries,
        private readonly Logger $logger,
        private readonly float $timeout = 60,
    ) {
    }

    /**
     * Sends the entry to all secondaries in parallel.
     *
     * @return list<string> hosts that failed to ACK
     */
    public function replicate(array $entry): array
    {
        $wg     = new WaitGroup();
        $failed = [];

        foreach ($this->secondaries() as $host) {
            $wg->add();
            Coroutine::create(function () use ($host, $entry, $wg, &$failed): void {
                try {
                    if (!$this->send($host, $entry)) {
                        $failed[] = $host;
                    }
                } finally {
                    $wg->done();
                }
            });
        }
        $wg->wait();

        return $failed;
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
