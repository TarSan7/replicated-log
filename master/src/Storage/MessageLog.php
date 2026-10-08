<?php
declare(strict_types=1);

namespace Master\Storage;

/** In-memory log. Safe as long as the server runs a single worker process. */
final class MessageLog
{
    /** @var list<array{id: int, message: string}> */
    private array $entries = [];

    public function append(array $entry): void
    {
        $this->entries[] = $entry;
    }

    public function nextId(): int
    {
        return count($this->entries) + 1;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_column($this->entries, 'message');
    }
}
