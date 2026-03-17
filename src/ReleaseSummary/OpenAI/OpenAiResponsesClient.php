<?php

declare(strict_types=1);

namespace App\ReleaseSummary\OpenAI;

use App\Env;
use App\HttpClient;
use App\Logger;

final class OpenAiResponsesClient
{
    private HttpClient $httpClient;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->baseUrl = rtrim(Env::get('OPENAI_BASE_URL', 'https://api.openai.com/v1') ?? 'https://api.openai.com/v1', '/');
        $this->apiKey = Env::require('OPENAI_API_KEY');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createResponse(array $payload): array
    {
        $response = $this->httpClient->request(
            'POST',
            $this->baseUrl . '/responses',
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ],
            $payload
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::error('OpenAI summary request failed', [
                'status' => $response['status'],
                'body' => $response['body'],
                'endpoint' => $this->baseUrl . '/responses',
            ]);
            throw new \RuntimeException(sprintf('OpenAI returned HTTP %d: %s', $response['status'], $response['body']));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            Logger::error('OpenAI summary response had invalid JSON', [
                'body' => $response['body'],
            ]);
            throw new \RuntimeException('Unexpected OpenAI response format');
        }

        return $decoded;
    }
}
