<?php

declare(strict_types=1);

namespace App\ReleaseSummary\DTO;

final class SummaryResult
{
    /**
     * @param array<int, string> $bullets
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $text,
        public readonly array $bullets,
        public readonly array $meta = [],
        public readonly mixed $rawOutput = null
    ) {
    }
}
