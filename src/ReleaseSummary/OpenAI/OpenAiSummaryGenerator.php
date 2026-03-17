<?php

declare(strict_types=1);

namespace App\ReleaseSummary\OpenAI;

use App\Env;
use App\Logger;
use App\ReleaseSummary\Contracts\SummaryGeneratorInterface;
use App\ReleaseSummary\DTO\ReleaseIssue;
use App\ReleaseSummary\DTO\SummaryResult;

final class OpenAiSummaryGenerator implements SummaryGeneratorInterface
{
    private OpenAiResponsesClient $client;
    private OpenAiPromptBuilder $promptBuilder;
    private string $model;

    public function __construct(?OpenAiResponsesClient $client = null, ?OpenAiPromptBuilder $promptBuilder = null)
    {
        $this->client = $client ?? new OpenAiResponsesClient();
        $this->promptBuilder = $promptBuilder ?? new OpenAiPromptBuilder();
        $this->model = Env::get('OPENAI_SUMMARY_MODEL', 'gpt-5-mini') ?? 'gpt-5-mini';
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     */
    public function generate(string $release, array $issues): SummaryResult
    {
        if ($issues === []) {
            return new SummaryResult(
                mode: $this->mode(),
                text: '- No Jira issues were found for this release.',
                bullets: ['No Jira issues were found for this release.'],
                meta: ['provider' => 'openai', 'model' => $this->model, 'issues_count' => 0],
                rawOutput: ['provider' => 'openai', 'empty' => true]
            );
        }

        $promptData = $this->promptBuilder->build($release, $issues);

        $payload = [
            'model' => $this->model,
            'instructions' => $this->promptBuilder->instructions(),
            'input' => $promptData['input'],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'release_summary',
                    'strict' => true,
                    'schema' => $this->responseSchema(),
                ],
            ],
        ];

        $response = $this->client->createResponse($payload);
        $structured = $this->decodeStructuredOutput($response);

        $bullets = $this->buildGroupedBullets($structured);
        if ($bullets === []) {
            Logger::error('OpenAI summary returned empty grouped summary', [
                'release' => $release,
                'model' => $this->model,
            ]);
            throw new \RuntimeException('OpenAI summary returned no grouped summary');
        }
        $text = implode("\n", array_map(static fn (string $line): string => '- ' . $line, $bullets));

        return new SummaryResult(
            mode: $this->mode(),
            text: $text,
            bullets: $bullets,
            meta: [
                'provider' => 'openai',
                'model' => $this->model,
                'overview' => (string) ($structured['overview'] ?? ''),
                'groups' => $structured['groups'] ?? [],
                'risks' => $structured['risks'] ?? [],
                'issues_count' => count($issues),
                'prompt' => $promptData['meta'],
            ],
            rawOutput: [
                'structured' => $structured,
                'response_id' => $response['id'] ?? null,
                'model' => $response['model'] ?? $this->model,
            ]
        );
    }

    public function mode(): string
    {
        return 'openai';
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
            throw new \RuntimeException('Unable to parse OpenAI summary JSON');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractOutputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text']) && trim($response['output_text']) !== '') {
            return $response['output_text'];
        }

        $output = $response['output'] ?? null;
        if (!is_array($output)) {
            throw new \RuntimeException('OpenAI response did not include output text');
        }

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                if (($contentItem['type'] ?? null) === 'output_text' && is_string($contentItem['text'] ?? null)) {
                    return $contentItem['text'];
                }
            }
        }

        throw new \RuntimeException('OpenAI response did not include output_text content');
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
            $details = $this->normalizeDetails($group['details'] ?? []);
            $exampleKeys = $this->normalizeExampleKeys($group['example_keys'] ?? []);

            if ($title === '' || $summary === '') {
                continue;
            }

            $line = sprintf('%s: %s', $title, $summary);

            if ($details !== []) {
                $line .= ' Детали: ' . implode(' ', $details);
            }

            if ($exampleKeys !== []) {
                $line .= sprintf(' Примеры задач: %s.', implode(', ', $exampleKeys));
            }

            $result[] = $line;
        }

        $risks = $structured['risks'] ?? null;
        if (is_array($risks)) {
            $riskItems = [];

            foreach ($risks as $risk) {
                if (!is_string($risk)) {
                    continue;
                }

                $risk = trim($risk);
                if ($risk !== '') {
                    $riskItems[] = $risk;
                }
            }

            if ($riskItems !== []) {
                $result[] = 'Риски и замечания: ' . implode('; ', $riskItems) . '.';
            }
        }

        return $result;
    }

    /**
     * @param mixed $details
     * @return array<int, string>
     */
    private function normalizeDetails(mixed $details): array
    {
        if (!is_array($details)) {
            return [];
        }

        $result = [];

        foreach ($details as $detail) {
            if (!is_string($detail)) {
                continue;
            }

            $detail = trim($detail);
            if ($detail !== '') {
                $result[] = $detail;
            }
        }

        return $result;
    }

    /**
     * @param mixed $keys
     * @return array<int, string>
     */
    private function normalizeExampleKeys(mixed $keys): array
    {
        if (!is_array($keys)) {
            return [];
        }

        $result = [];

        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }

            $key = trim($key);
            if ($key !== '') {
                $result[] = $key;
            }
        }

        return array_values(array_unique($result));
    }

}
