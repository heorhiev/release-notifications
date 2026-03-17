<?php

declare(strict_types=1);

namespace App\Slack;

use App\Env;
use App\HttpClient;
use App\Logger;

final class IncomingWebhookTransport implements SlackTransportInterface
{
    private HttpClient $httpClient;
    private string $webhookUrl;
    private ?string $channel;
    private string $username;
    private string $iconEmoji;

    public function __construct(?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->webhookUrl = Env::require('SLACK_WEBHOOK_URL');
        $this->channel = Env::get('SLACK_CHANNEL');
        $this->username = Env::get('SLACK_USERNAME', 'Release Bot') ?? 'Release Bot';
        $this->iconEmoji = Env::get('SLACK_ICON_EMOJI', ':rocket:') ?? ':rocket:';
    }

    public function sendSummary(string $summary): void
    {
        $payload = [
            'text' => $summary,
            'username' => $this->username,
            'icon_emoji' => $this->iconEmoji,
        ];

        if ($this->channel !== null) {
            $payload['channel'] = $this->channel;
        }

        $response = $this->httpClient->request('POST', $this->webhookUrl, [], $payload);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::error('Slack incoming webhook request failed', [
                'status' => $response['status'],
                'body' => $response['body'],
            ]);
            throw new \RuntimeException(sprintf('Slack returned HTTP %d: %s', $response['status'], $response['body']));
        }
    }
}
