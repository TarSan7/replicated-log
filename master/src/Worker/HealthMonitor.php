<?php
declare(strict_types=1);

namespace Master\Worker;

use Master\Logger;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

class HealthMonitor
{
    public const HEALTHY = 'healthy';
    public const SUSPECTED = 'suspected';
    public const UNHEALTHY = 'unhealthy';

    /** @var array<string, array{status: string, failures: int, last_seen: ?string}> keyed by host:port */
    public array $statuses = [];

    /** @var array<string, bool> hosts whose previous check hasn't finished yet */
    private array $inFlight = [];

    public function __construct(
        private readonly array $secondaries,
        private readonly Logger $logger,
        private readonly float $timeout = 1,
        private readonly int $unhealthyAfter = 3,
    ) {
        foreach ($this->secondaries as $host) {
            $this->statuses[$host] = ['status' => self::HEALTHY, 'failures' => 0, 'last_seen' => null];
        }
    }

    public function checkAll(): void
    {
        foreach ($this->secondaries as $host) {
            if ($this->inFlight[$host] ?? false) {
                continue;
            }
            $this->inFlight[$host] = true;

            Coroutine::create(function () use ($host): void {
                try {
                    $this->update($host, $this->ping($host));
                } finally {
                    $this->inFlight[$host] = false;
                }
            });
        }
    }

    /** @return array<string, array{status: string, failures: int, last_seen: ?string}> */
    public function all(): array
    {
        return $this->statuses;
    }

    public function status(string $host): string
    {
        return $this->statuses[$host]['status'];
    }

    public function aliveNodes(): int
    {
        $alive = array_filter($this->statuses, fn (array $entry) => $entry['status'] !== self::UNHEALTHY);

        return 1 + count($alive);
    }

    public function quorumSize(): int
    {
        return intdiv(count($this->secondaries) + 1, 2) + 1;
    }

    public function hasQuorum(): bool
    {
        return $this->aliveNodes() >= $this->quorumSize();
    }

    private function ping(string $host): bool
    {
        [$h, $p] = explode(':', $host);
        $client = new Client($h, (int)$p);
        $client->set(['timeout' => $this->timeout]);
        $client->get('/health');
        $ok = $client->statusCode === 200;
        $client->close();

        return $ok;
    }

    private function update(string $host, bool $ok): void
    {
        $entry = $this->statuses[$host];
        $previous = $entry['status'];
        $hadQuorum = $this->hasQuorum();

        if ($ok) {
            $entry['failures'] = 0;
            $entry['last_seen'] = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP');
            $entry['status'] = self::HEALTHY;
        } else {
            $entry['failures']++;
            $entry['status'] = $entry['failures'] >= $this->unhealthyAfter ? self::UNHEALTHY : self::SUSPECTED;
        }
        $this->statuses[$host] = $entry;

        if ($entry['status'] !== $previous) {
            $context = ['host' => $host, 'from' => $previous, 'to' => $entry['status'], 'failures' => $entry['failures']];
            $entry['status'] === self::HEALTHY
                ? $this->logger->info('secondary status changed', $context)
                : $this->logger->warning('secondary status changed', $context);
        }

        if ($this->hasQuorum() !== $hadQuorum) {
            $context = ['alive' => $this->aliveNodes(), 'quorum' => $this->quorumSize()];
            $hadQuorum
                ? $this->logger->warning('quorum lost, master is read-only', $context)
                : $this->logger->info('quorum restored, master accepts appends again', $context);
        }
    }
}
