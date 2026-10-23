<?php

namespace Tests\Fakes;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps what it was given, so tests can assert on
 * log records (and on what is absent from them) without a log file.
 */
class CollectingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->records, fn (array $r) => $r['level'] === 'error'));
    }
}
