<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\Support\MasterNode;
use Tests\Support\SecondaryNode;
use Tests\Support\Transport;

final class ReplicationTest extends TestCase
{
    public function testMasterWaitsForAckFromAllSecondaries(): void
    {
        $this->inCoroutine(function (): void {
            [$transport, $master, $secondary1, $secondary2] = $this->cluster();

            $transport->setDelay($master, $secondary1, 1.0);

            $start    = microtime(true);
            $response = $master->appendMessage('m1', 3);
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
            [$transport, $master, $secondary1, $secondary2] = $this->cluster();

            $transport->setDelay($master, $secondary1, 2.0);
            $transport->setDelay($master, $secondary2, 2.0);

            $start = microtime(true);
            $master->appendMessage('m1', 3);
            $elapsed = microtime(true) - $start;

            // Sequential replication would take ~4s.
            $this->assertGreaterThanOrEqual(2.0, $elapsed);
            $this->assertLessThan(2.5, $elapsed, 'secondaries were replicated to one after another');
        });
    }

    public function testReplicationToSecondariesWaitConcern(): void
    {
        $this->inCoroutine(function (): void {
            [$transport, $master, $secondary1, $secondary2] = $this->cluster();
            $delay = (float)random_int(5, 10);
            $transport->setDelay($master, $secondary2, $delay);

            $this->assertRespondsQuickly(fn () => $master->appendMessage('Msg1', 1));
            $this->assertRespondsQuickly(fn () => $master->appendMessage('Msg2', 2));

            // W=3 has to wait for the delayed S2, so it runs in its own coroutine.
            $msg3      = new Channel(1);
            $msg3Start = microtime(true);
            Coroutine::create(static fn () => $msg3->push($master->appendMessage('Msg3', 3)));

            $this->assertRespondsQuickly(fn () => $master->appendMessage('Msg4', 1));
            $this->assertTrue($msg3->isEmpty(), 'Msg3 (W=3) returned before S2 acknowledged');

            $msg3Response = $msg3->pop($delay + 2);
            $this->assertNotFalse($msg3Response, 'Msg3 (W=3) never returned');
            $this->assertSame(201, $msg3Response->status);
            $this->assertGreaterThanOrEqual($delay, microtime(true) - $msg3Start);

            $this->assertSame(['Msg1', 'Msg2', 'Msg3'], array_slice($secondary2->listMessages(), 0, 3));

            $all = ['Msg1', 'Msg2', 'Msg3', 'Msg4'];
            $this->assertSame($all, $master->listMessages());
            $this->assertSame($all, $secondary1->listMessages());

            Coroutine::sleep(0.5);
            $this->assertSame($all, $secondary2->listMessages());
        });
    }

    public function testReorderedMessagesAreListedInOrder(): void
    {
        $this->inCoroutine(function (): void {
            [$transport, $master, $secondary1] = $this->cluster();

            $transport->reorderMessages($master, $secondary1, 2);

            $master->appendMessage('m1', 1);
            $this->assertSame([], $secondary1->listMessages(), 'm1 should be held by the transport');

            $master->appendMessage('m2', 1);
            $this->waitUntil(fn () => count($transport->deliveredTo($secondary1)) === 2);
            $this->assertSame([2, 1], $transport->deliveredTo($secondary1), 'messages were not reordered');

            $this->assertSame(['m1', 'm2'], $secondary1->listMessages());
        });
    }

    public function testSeveralReorderedMessagesAreListedInOrder(): void
    {
        $this->inCoroutine(function (): void {
            [$transport, $master, $secondary1] = $this->cluster();

            $transport->reorderMessages($master, $secondary1, 3);

            $master->appendMessage('m1', 1);
            $master->appendMessage('m2', 1);
            $master->appendMessage('m3', 1);
            $this->waitUntil(fn () => count($transport->deliveredTo($secondary1)) === 3);

            $this->assertSame([3, 2, 1], $transport->deliveredTo($secondary1), 'messages were not reordered');
            $this->assertSame(['m1', 'm2', 'm3'], $secondary1->listMessages());
        });
    }

    public function testDuplicateMessageIsStoredOnce(): void
    {
        $secondary = new SecondaryNode('secondary1');

        $this->assertTrue($secondary->replicate(['id' => 1, 'message' => 'm1']));
        $this->assertTrue($secondary->replicate(['id' => 1, 'message' => 'm1']), 'duplicate should still be acked');

        $this->assertSame(['m1'], $secondary->listMessages());
    }

    /** @return array{Transport, MasterNode, SecondaryNode, SecondaryNode} */
    private function cluster(): array
    {
        $transport  = new Transport();
        $master     = new MasterNode($transport);
        $secondary1 = new SecondaryNode('secondary1');
        $secondary2 = new SecondaryNode('secondary2');
        $transport->connect($secondary1);
        $transport->connect($secondary2);

        return [$transport, $master, $secondary1, $secondary2];
    }

    private function assertRespondsQuickly(callable $append): void
    {
        $start    = microtime(true);
        $response = $append();

        $this->assertSame(201, $response->status);
        $this->assertLessThan(0.5, microtime(true) - $start, 'append was blocked by a delayed secondary');
    }

    private function waitUntil(callable $condition, float $timeout = 1.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                $this->fail("condition not met within {$timeout}s");
            }
            Coroutine::sleep(0.01);
        }
    }

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
