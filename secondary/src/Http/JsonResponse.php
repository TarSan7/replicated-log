<?php
declare(strict_types=1);

namespace Secondary\Http;

final class JsonResponse
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $status = 200,
    ) {
    }
}
