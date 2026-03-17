<?php

declare(strict_types=1);

namespace App\ReleaseSummary\Gemini;

use App\Env;
use App\HttpClient;
use App\Logger;

final class GeminiGenerateContentClient
{
    private HttpClient $httpClient;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->baseUrl = rtrim(
            Env::get('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta') ?? 'https://generativelanguage.googleapis.com/v1beta',
            '/'
        );
        $this->apiKey = Env::require('GEMINI_API_KEY');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generateContent(string $model, array $payload): array
    {
        $response = $this->httpClient->request(
            'POST',
            sprintf('%s/models/%s:generateContent', $this->baseUrl, rawurlencode($model)),
            [
                'x-goog-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ],
            $payload
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::error('Gemini summary request failed', [
                'status' => $response['status'],
                'body' => $response['body'],
                'model' => $model,
            ]);

            throw new \RuntimeException(sprintf('Gemini returned HTTP %d: %s', $response['status'], $response['body']));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            Logger::error('Gemini summary response had invalid JSON', [
                'body' => $response['body'],
                'model' => $model,
            ]);

            throw new \RuntimeException('Unexpected Gemini response format');
        }

        return $decoded;
    }
}
