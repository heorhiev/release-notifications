<?php

declare(strict_types=1);

namespace App\ReleaseSummary\Gemini;

use App\Env;
use App\Logger;
use App\ReleaseSummary\Contracts\SummaryGeneratorInterface;
use App\ReleaseSummary\DTO\ReleaseIssue;
use App\ReleaseSummary\DTO\SummaryResult;

final class GeminiSummaryGenerator implements SummaryGeneratorInterface
{
    private GeminiGenerateContentClient $client;
    private GeminiPromptBuilder $promptBuilder;
    private string $model;

    public function __construct(?GeminiGenerateContentClient $client = null, ?GeminiPromptBuilder $promptBuilder = null)
    {
        $this->client = $client ?? new GeminiGenerateContentClient();
        $this->promptBuilder = $promptBuilder ?? new GeminiPromptBuilder();
        $this->model = Env::get('GEMINI_SUMMARY_MODEL', 'gemini-2.5-flash') ?? 'gemini-2.5-flash';
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     */
    public function generate(string $release, array $issues): SummaryResult
    {
        if ($issues === []) {
            return new SummaryResult(
                mode: $this->mode(),
                text: '- В этом релизе не найдено задач Jira.',
                bullets: ['В этом релизе не найдено задач Jira.'],
                meta: ['provider' => 'gemini', 'model' => $this->model, 'issues_count' => 0],
                rawOutput: ['provider' => 'gemini', 'empty' => true]
            );
        }

        $promptData = $this->promptBuilder->build($release, $issues);

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $this->promptBuilder->instructions()],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $promptData['input']],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $this->responseSchema(),
            ],
        ];

        $response = $this->client->generateContent($this->model, $payload);
        $structured = $this->decodeStructuredOutput($response);
        $bullets = $this->buildGroupedBullets($structured);

        if ($bullets === []) {
            Logger::error('Gemini summary returned empty grouped summary', [
                'release' => $release,
                'model' => $this->model,
            ]);
            throw new \RuntimeException('Gemini summary returned no grouped summary');
        }

        $text = implode("\n", array_map(static fn (string $line): string => '- ' . $line, $bullets));

        return new SummaryResult(
            mode: $this->mode(),
            text: $text,
            bullets: $bullets,
            meta: [
                'provider' => 'gemini',
                'model' => $this->model,
                'overview' => (string) ($structured['overview'] ?? ''),
                'groups' => $structured['groups'] ?? [],
                'risks' => $structured['risks'] ?? [],
                'issues_count' => count($issues),
                'prompt' => $promptData['meta'],
            ],
            rawOutput: [
                'structured' => $structured,
                'model' => $response['modelVersion'] ?? $this->model,
                'response' => $response,
            ]
        );
    }

    public function mode(): string
    {
        return 'gemini';
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'overview' => [
                    'type' => 'string',
                    'description' => 'Общий развернутый абзац о релизе на русском языке.',
                ],
                'groups' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'details' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 2,
                                'maxItems' => 4,
                            ],
                            'example_keys' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'maxItems' => 3,
                            ],
                        ],
                        'required' => ['title', 'summary', 'details', 'example_keys'],
                    ],
                    'minItems' => 4,
                    'maxItems' => 6,
                ],
                'risks' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => 6,
                ],
            ],
            'required' => ['overview', 'groups', 'risks'],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function decodeStructuredOutput(array $response): array
    {
        $rawText = $this->extractOutputText($response);
        $decoded = json_decode($rawText, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Unable to parse Gemini summary JSON');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractOutputText(array $response): string
    {
        $candidates = $response['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            throw new \RuntimeException('Gemini response did not include candidates');
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $content = $candidate['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            $parts = $content['parts'] ?? null;
            if (!is_array($parts)) {
                continue;
            }

            $text = '';
            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }

            if (trim($text) !== '') {
                return $text;
            }
        }

        throw new \RuntimeException('Gemini response did not include text content');
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<int, string>
     */
    private function buildGroupedBullets(array $structured): array
    {
        $result = [];
        $overview = trim((string) ($structured['overview'] ?? ''));
        if ($overview !== '') {
            $result[] = $overview;
        }

        $groups = $structured['groups'] ?? null;
        if (!is_array($groups)) {
            return $result;
        }

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $title = trim((string) ($group['title'] ?? ''));
            $summary = trim((string) ($group['summary'] ?? ''));
            $details = $this->normalizeStrings($group['details'] ?? []);
            $exampleKeys = $this->normalizeStrings($group['example_keys'] ?? []);

            if ($title === '' || $summary === '') {
                continue;
            }

            $line = sprintf('%s: %s', $title, $summary);

            if ($details !== []) {
                $line .= ' Детали: ' . implode(' ', $details);
            }

            if ($exampleKeys !== []) {
                $line .= sprintf(' Примеры задач: %s.', implode(', ', array_values(array_unique($exampleKeys))));
            }

            $result[] = $line;
        }

        $risks = $this->normalizeStrings($structured['risks'] ?? []);
        if ($risks !== []) {
            $result[] = 'Риски и замечания: ' . implode('; ', $risks) . '.';
        }

        return $result;
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function normalizeStrings(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }
}
