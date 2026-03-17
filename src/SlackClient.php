<?php

declare(strict_types=1);

namespace App;

use App\Slack\IncomingWebhookTransport;
use App\Slack\SlackTransportInterface;
use App\Slack\WorkflowTriggerTransport;

final class SlackClient
{
    private SlackTransportInterface $transport;

    public function __construct(?SlackTransportInterface $transport = null)
    {
        $this->transport = $transport ?? $this->resolveTransport();
    }

    public function sendMessage(string $text): void
    {
        $this->transport->sendSummary($text);
    }

    private function resolveTransport(): SlackTransportInterface
    {
        $transport = strtolower(trim(Env::get('SLACK_TRANSPORT', 'incoming_webhook') ?? 'incoming_webhook'));

        return match ($transport) {
            'workflow_trigger' => new WorkflowTriggerTransport(),
            'incoming_webhook' => new IncomingWebhookTransport(),
            default => throw new \RuntimeException(sprintf('Unsupported Slack transport: %s', $transport)),
        };
    }
}
