<?php

declare(strict_types=1);

namespace App\Contracts;

interface SlackClientInterface
{
    public function sendMessage(string $text, ?string $releaseUrl = null): void;
}
