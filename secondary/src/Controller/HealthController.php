<?php
declare(strict_types=1);

namespace Secondary\Controller;

use Secondary\Http\JsonResponse;
use Swoole\Http\Request;

final class HealthController
{
    public function __construct()
    {
    }

    public function check(Request $request): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
