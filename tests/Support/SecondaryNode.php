<?php
declare(strict_types=1);

namespace Tests\Support;

use Secondary\Controller\MessageController;
use Secondary\Controller\ReplicationController;
use Secondary\Logger;
use Secondary\Storage\MessageLog;
use Swoole\Http\Request;

final class SecondaryNode
{
    private ReplicationController $replication;
    private MessageController $messages;

    public function __construct(public readonly string $name)
    {
        $log               = new MessageLog();
        $logger            = new Logger($name);
        $this->replication = new ReplicationController($log, $logger, delayMs: 0);
        $this->messages    = new MessageController($log);
    }

    public function replicate(array $entry): bool
    {
        return $this->replication->replicate(FakeRequest::json('POST', '/replicate', $entry))->status === 200;
    }

    public function listMessages(): array
    {
        return $this->messages->index(FakeRequest::json('GET', '/messages'))->data;
    }
}
