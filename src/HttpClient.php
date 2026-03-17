<?php

declare(strict_types=1);

namespace App;

final class HttpClient
{
    private int $timeoutSeconds;

    public function __construct(?int $timeoutSeconds = null)
    {
        $this->timeoutSeconds = $timeoutSeconds ?? 90;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @return array{status:int, headers:array<int, string>, body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?array $json = null): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[] = sprintf('%s: %s', $name, $value);
        }

        $content = null;
        if ($json !== null) {
            $content = json_encode(
                $json,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($content === false) {
                throw new \RuntimeException(sprintf('Unable to encode JSON payload for %s %s', strtoupper($method), $url));
            }

            $normalizedHeaders[] = 'Content-Type: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $normalizedHeaders),
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => $this->timeoutSeconds,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];

        if ($body === false) {
            $error = error_get_last();
            $message = is_array($error) && is_string($error['message'] ?? null)
                ? $error['message']
                : 'Unknown transport error';

            throw new \RuntimeException(sprintf(
                'HTTP request failed: %s %s (%s)',
                strtoupper($method),
                $url,
                $message
            ));
        }

        $status = $this->extractStatusCode($responseHeaders);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $body,
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function extractStatusCode(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
