<?php

declare(strict_types=1);

namespace App\Slack;

use App\Env;
use App\HttpClient;
use App\Logger;

final class WorkflowTriggerTransport implements SlackTransportInterface
{
    private HttpClient $httpClient;
    private string $triggerUrl;

    public function __construct(?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->triggerUrl = Env::require('SLACK_WEBHOOK_URL');
    }

    public function sendSummary(string $summary): void
    {
        $payload = $this->buildWorkflowPayload($summary);

        $response = $this->httpClient->request('POST', $this->triggerUrl, [], $payload);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::error('Slack workflow trigger request failed', [
                'status' => $response['status'],
                'body' => $response['body'],
                'payload_keys' => array_keys($payload),
            ]);
            throw new \RuntimeException(sprintf('Slack workflow trigger returned HTTP %d: %s', $response['status'], $response['body']));
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildWorkflowPayload(string $summary): array
    {
        $summary = $this->stripSlackLinks(trim(str_replace("\r\n", "\n", $summary)));
        $blocks = preg_split('/\n{2,}/u', $summary) ?: [];

        $payload = [
            'Title' => '',
            'Overview' => '',
            'Section1Title' => '',
            'Section1Body' => '',
            'Section2Title' => '',
            'Section2Body' => '',
            'Section3Title' => '',
            'Section3Body' => '',
            'Section4Title' => '',
            'Section4Body' => '',
            'Section5Title' => '',
            'Section5Body' => '',
            'Section6Title' => '',
            'Section6Body' => '',
            'Section7Title' => '',
            'Section7Body' => '',
            'Risks' => '',
        ];

        $sectionIndex = 1;

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            if (preg_match('/^\*(.+)\*$/us', $block, $matches) === 1) {
                $payload['Title'] = trim($matches[1]);
                continue;
            }

            if (preg_match('/^\*(.+)\*\n(.+)$/us', $block, $matches) !== 1) {
                if ($payload['Overview'] === '') {
                    $payload['Overview'] = $block;
                }
                continue;
            }

            $title = trim($matches[1]);
            $body = trim($matches[2]);

            if ($title === 'Overview') {
                $payload['Overview'] = $body;
                continue;
            }

            if ($title === 'Риски и замечания') {
                $payload['Risks'] = $body;
                continue;
            }

            if ($sectionIndex > 7) {
                $existing = $payload['Section7Body'];
                $merged = $existing === '' ? $body : $existing . "\n\n" . $title . "\n" . $body;
                $payload['Section7Body'] = $merged;
                continue;
            }

            $payload[sprintf('Section%dTitle', $sectionIndex)] = $title;
            $payload[sprintf('Section%dBody', $sectionIndex)] = $body;
            $sectionIndex++;
        }

        if ($payload['Title'] === '') {
            $payload['Title'] = 'Release Summary';
        }

        return $payload;
    }

    private function stripSlackLinks(string $text): string
    {
        return preg_replace_callback(
            '/<([^>|]+)\|([^>]+)>/u',
            static fn (array $matches): string => $matches[2],
            $text
        ) ?? $text;
    }
}
