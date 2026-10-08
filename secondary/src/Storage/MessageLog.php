<?php
declare(strict_types=1);

namespace Secondary\Storage;

/** In-memory log. Safe as long as the server runs a single worker process. */
final class MessageLog
{
    /** @var list<array{id: int, message: string}> */
    private array $entries = [];

    public function append(array $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_column($this->entries, 'message');
    }
}
