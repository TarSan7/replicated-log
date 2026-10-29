<?php
declare(strict_types=1);

namespace Secondary\Storage;

/** In-memory log. Safe as long as the server runs a single worker process. */
final class MessageLog
{
    /** @var list<array{id: int, message: string}> */
    private array $entries = [];

    /** @var array<int, array{id: int, message: string}> */
    private array $buffer = [];

    private int $nextId = 1;

    /** @return array{status: 'stored'|'buffered'|'duplicate', ids: list<int>, waiting_for?: int} */
    public function append(array $entry): array
    {
        $id = $entry['id'];

        if ($id < $this->nextId || isset($this->buffer[$id])) {
            return ['status' => 'duplicate', 'ids' => []];
        }
        $this->buffer[$id] = $entry;

        if ($id > $this->nextId) {
            return ['status' => 'buffered', 'ids' => [], 'waiting_for' => $this->nextId];
        }
        $flushedIds = [];

        while (isset($this->buffer[$this->nextId])) {
            $this->entries[] = $this->buffer[$this->nextId];
            unset($this->buffer[$this->nextId]);
            $flushedIds[] = $this->nextId;
            $this->nextId++;
        }

        return ['status' => 'stored', 'ids' => $flushedIds];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_column($this->entries, 'message');
    }
}
