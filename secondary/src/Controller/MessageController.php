<?php
declare(strict_types=1);

namespace Secondary\Controller;

use Secondary\Http\JsonResponse;
use Secondary\Storage\MessageLog;
use Swoole\Http\Request;

final class MessageController
{
    public function __construct(private readonly MessageLog $log)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse($this->log->messages());
    }
}
