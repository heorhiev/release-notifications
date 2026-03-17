<?php

declare(strict_types=1);

namespace App\Slack;

interface SlackTransportInterface
{
    public function sendSummary(string $summary): void;
}
