<?php
declare(strict_types=1);

namespace Tests\Support;

use Swoole\Http\Request;

final class FakeRequest
{
    public static function json(string $method, string $uri, mixed $data = null): Request
    {
        $body    = $data === null ? '' : json_encode($data);
        $request = Request::create();
        $request->parse("$method $uri HTTP/1.1\r\nContent-Type: application/json\r\nContent-Length: "
            . strlen($body) . "\r\n\r\n$body");
        return $request;
    }
}
