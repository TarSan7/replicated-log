<?php
declare(strict_types=1);

namespace Master;

/** Writes one line per event to stdout (visible in `docker compose logs`). */
final class Logger
{
    public function __construct(private readonly string $node)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            '%s [%s] %-5s %s%s',
            (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            $this->node,
            $level,
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        );
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
