<?php
declare(strict_types=1);

namespace Tests\Support;

use Master\Controller\MessageController;
use Master\Http\JsonResponse;
use Master\Logger;
use Master\Storage\MessageLog;
use Swoole\Http\Request;

final class MasterNode
{
    private MessageController $messages;

    public function __construct(Transport $transport)
    {
        $logger         = new Logger('master');
        $this->messages = new MessageController(new MessageLog(), $transport, $logger);
    }

    public function appendMessage(string $message): JsonResponse
    {
        return $this->messages->store(FakeRequest::json('POST', '/messages', ['message' => $message]));
    }

    public function listMessages(): array
    {
        return $this->messages->index(FakeRequest::json('GET', '/messages'))->data;
    }
}
