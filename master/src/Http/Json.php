<?php
declare(strict_types=1);

namespace Master\Http;

use Swoole\Http\Request;
use Swoole\Http\Response;

final class Json
{
    public static function body(Request $request): mixed
    {
        return json_decode($request->rawContent() ?: 'null', true);
    }

    public static function send(Response $response, JsonResponse $json): void
    {
        $response->status($json->status);
        $response->header('content-type', 'application/json');
        $response->end(json_encode($json->data));
    }
}
