<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Epic
{
    public function __construct(
        public int $id,
        public string $jiraKey,
        public int $priority,
    ) {
    }
}
