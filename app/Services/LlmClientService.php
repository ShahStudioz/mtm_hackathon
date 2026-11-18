<?php

namespace App\Services;

use App\Enums\PlanArea;
use App\Models\LlmTrace;
use App\Models\PlanState;
use Illuminate\Support\Facades\Http;

class LlmClientService
{
    /**
     * Call the LLM provider and record the call trace.
     *
     * @return array{
     *     content: mixed,
     *     data: array<string, mixed>|null,
     *     raw_content: string,
     *     trace: LlmTrace
     * }
     */
    public function call(
        PlanState $state,
        int $batchNumber,
        PlanArea $area,
        string $systemPrompt,
        string $userPrompt,
        float $temperature = 0.0
    ): array {
        $baseUrl = rtrim((string) config('services.llm.base_url', 'https://api.x.ai/v1'), '/');
        $apiKey = (string) config('services.llm.api_key', '');
        $defaultModel = (string) config('services.llm.model', 'grok-2-latest');
        $timeout = (int) config('services.llm.timeout', 30);

        $messages = [];
        if (! empty(trim($systemPrompt))) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $userPrompt,
        ];

        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->post("{$baseUrl}/chat/completions", [
                'model' => $defaultModel,
                'messages' => $messages,
                'temperature' => $temperature,
            ]);

        $response->throw();

        $responseData = $response->json();

        $modelName = (string) ($responseData['model'] ?? $defaultModel);
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? 0);

        $rawOutput = (string) ($responseData['choices'][0]['message']['content'] ?? '');

        $inputPayload = ! empty(trim($systemPrompt))
            ? "System: {$systemPrompt}\nUser: {$userPrompt}"
            : "User: {$userPrompt}";

        $nextCallNumber = ((int) $state->llmTraces()->max('call_number')) + 1;

        /** @var LlmTrace $trace */
        $trace = $state->llmTraces()->create([
            'batch_number' => $batchNumber,
            'call_number' => $nextCallNumber,
            'model_name' => $modelName,
            'area' => $area,
            'input_payload' => $inputPayload,
            'output_payload' => $rawOutput,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ]);

        $parsedJson = $this->parseJson($rawOutput);

        return [
            'content' => $parsedJson ?? $rawOutput,
            'data' => is_array($parsedJson) ? $parsedJson : null,
            'raw_content' => $rawOutput,
            'trace' => $trace,
        ];
    }

    /**
     * Attempt to parse string output as JSON, stripping markdown code blocks if necessary.
     */
    protected function parseJson(string $rawOutput): mixed
    {
        $trimmed = trim($rawOutput);

        if (str_starts_with($trimmed, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
            $cleaned = preg_replace('/\s*```$/', '', (string) $cleaned);
            $trimmed = trim((string) $cleaned);
        }

        $decoded = json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
