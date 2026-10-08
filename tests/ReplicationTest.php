<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Tests\Support\MasterNode;
use Tests\Support\SecondaryNode;
use Tests\Support\Transport;

final class ReplicationTest extends TestCase
{
    public function testMasterWaitsForAckFromAllSecondaries(): void
    {
        $this->inCoroutine(function (): void {
            $transport = new Transport();
            $master    = new MasterNode($transport);
            $secondary1 = new SecondaryNode('secondary1');
            $secondary2 = new SecondaryNode('secondary2');
            $transport->connect($secondary1);
            $transport->connect($secondary2);

            $transport->setDelay($master, $secondary1, 1.0);

            $start    = microtime(true);
            $response = $master->appendMessage('m1');
            $elapsed  = microtime(true) - $start;

            $this->assertSame(201, $response->status);
            $this->assertGreaterThanOrEqual(1.0, $elapsed, 'master returned before the slow secondary acknowledged');

            $this->assertSame(['m1'], $master->listMessages());
            $this->assertSame(['m1'], $secondary1->listMessages());
            $this->assertSame(['m1'], $secondary2->listMessages());
        });
    }

    public function testReplicationToSecondariesRunsInParallel(): void
    {
        $this->inCoroutine(function (): void {
            $transport = new Transport();
            $master    = new MasterNode($transport);
            $secondary1 = new SecondaryNode('secondary1');
            $secondary2 = new SecondaryNode('secondary2');
            $transport->connect($secondary1);
            $transport->connect($secondary2);

            $transport->setDelay($master, $secondary1, 2.0);
            $transport->setDelay($master, $secondary2, 2.0);

            $start = microtime(true);
            $master->appendMessage('m1');
            $elapsed = microtime(true) - $start;

            // Sequential replication would take ~2s.
            $this->assertGreaterThanOrEqual(2.0, $elapsed);
            $this->assertLessThan(2.5, $elapsed, 'secondaries were replicated to one after another');
        });
    }

    /** Runs the test body inside Swoole, which Replicator's coroutines and WaitGroup require. */
    private function inCoroutine(callable $test): void
    {
        $error = null;
        Coroutine\run(static function () use ($test, &$error): void {
            try {
                $test();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }
}
