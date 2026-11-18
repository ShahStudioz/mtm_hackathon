<?php

namespace App\Services;

use App\Enums\PlanArea;
use App\Models\PlanState;
use Illuminate\Support\Facades\File;

class MessageReaderService
{
    public function __construct(
        protected LlmClientService $llmClient
    ) {}

    /**
     * Read a raw message file from disk and perform an LLM reading extraction call.
     *
     * @return array{
     *     messages: array<int, array<string, mixed>>,
     *     raw_content: string,
     *     trace: mixed
     * }
     */
    public function readFile(PlanState $state, string $filePath, int $batchNumber): array
    {
        $rawText = File::get($filePath);

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = "Please parse the following message(s):\n\n".$rawText;

        $result = $this->llmClient->call(
            state: $state,
            batchNumber: $batchNumber,
            area: PlanArea::Reading,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            temperature: 0.0
        );

        $data = $result['data'] ?? [];
        $rawMessages = $data['messages'] ?? ($result['content']['messages'] ?? []);

        if (! is_array($rawMessages) || empty($rawMessages)) {
            // If the LLM returned a single message object instead of wrapping in messages array
            if (isset($data['sender']) || isset($data['facts'])) {
                $rawMessages = [$data];
            } else {
                $rawMessages = [];
            }
        }

        $normalizedMessages = $this->normalizeExtractedMessages($rawMessages, $rawText);

        return [
            'messages' => $normalizedMessages,
            'raw_content' => $rawText,
            'trace' => $result['trace'],
        ];
    }

    /**
     * Concise, token-efficient system prompt for PlanArea::Reading.
     */
    protected function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a precise event planning data extraction agent.
Parse the input text into a strict JSON object with a single top-level key "messages" containing an array of message objects.
The text may contain one or multiple messages separated by lines or message headers.
Messages may be in English or Roman Urdu.

Each object in the "messages" array must have:
- "raw_index": string/int or null
- "sender": string
- "date_str": string or null
- "normalized_date_sort": integer YYYYMMDD (assume 2026 if only day/month given, or sequential number if no date)
- "raw_content": string (exact message text)
- "is_unapproved_wish": boolean (true IF the sender is asking/wishing for an optional non-essential expense without organizer approval, e.g. DJ, concert)
- "intent": string ("directive" | "vendor_quote" | "constraint_update" | "forced_override" | "unapproved_request" | "irrelevant")
- "facts": object with ONLY present/extracted keys (OMIT null or empty fields to keep output compact):
  - "duration_hours": number
  - "students_count": int
  - "team_size": int
  - "teams_count": int
  - "staff_count": int
  - "extra_participants_count": int
  - "total_budget_limit": number (CRITICAL: overall event budget cap whenever mentioned, e.g. "budget is Rs 650,000" -> 650000)
  - "schedule_constraints": array of objects [{ "type": string, "description": string, "duration_minutes": number, "delay_minutes": number }]
  - "venue_options": array of objects [{ "name": string, "capacity": int, "cost": number, "features": array of strings }]
  - "venue_requirements": array of strings
  - "catering_options": array of objects [{ "name": string, "cost_per_head": number, "delivery_cost": number, "forced_box_count": number }]
  - "judging_rules": object { "confirmed_judges": int, "honorarium_per_judge": number, "minutes_per_team": number, "max_teams_per_judge": number }
  - "fixed_expenses": array of objects [{ "item": string, "cost": number, "is_approved": boolean }]
  - "custom_constraints": array of objects [{ "area": "venue|schedule|food|judging|budget", "rule_type": "min|max|required|forbidden|ratio", "target": string, "value": number|string|null, "description": string }]

Critical extraction guidelines:
1. Extract ALL numbers accurately from the text without hallucinating or modifying them.
2. Always include a "facts" object for each message (use empty object {} if no facts present).
3. For Roman Urdu messages, accurately understand quotes and item options.
4. If a message specifies forced food boxes (e.g. rounded down to N boxes), set catering_options with forced_box_count=N and intent="forced_override".
5. If a message updates a single bill/expense, set fixed_expenses accordingly and intent="constraint_update".
6. Explicitly capture ANY domain constraint, requirement, exclusion, dietary rule (e.g. vegetarian or halal counts), equipment or accessibility rule (e.g. standby generator, wheelchair access, projector, Wi-Fi), category budget cap, break interval rule, or judge workload/shift limit into "custom_constraints" with area, rule_type, target, value, and description.
7. Return ONLY a valid JSON object matching this schema. No markdown explanations outside JSON.
PROMPT;
    }

    /**
     * Normalize extracted message array and guarantee valid date sorting values.
     *
     * @param  array<int, mixed>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeExtractedMessages(array $messages, string $rawFileContent): array
    {
        $normalized = [];

        foreach ($messages as $index => $msg) {
            if (! is_array($msg)) {
                continue;
            }

            $dateStr = isset($msg['date_str']) && is_string($msg['date_str']) ? trim($msg['date_str']) : null;
            $sortDate = isset($msg['normalized_date_sort']) && is_numeric($msg['normalized_date_sort'])
                ? (int) $msg['normalized_date_sort']
                : $this->parseDateToSortable($dateStr, $index);

            $rawContent = (string) ($msg['raw_content'] ?? '');
            if (empty($rawContent) && count($messages) === 1) {
                $rawContent = trim($rawFileContent);
            }

            $normalized[] = [
                'raw_index' => isset($msg['raw_index']) ? (string) $msg['raw_index'] : (string) ($index + 1),
                'sender' => (string) ($msg['sender'] ?? 'Unknown'),
                'date_str' => $dateStr,
                'normalized_date_sort' => $sortDate,
                'raw_content' => $rawContent,
                'is_unapproved_wish' => (bool) ($msg['is_unapproved_wish'] ?? false),
                'intent' => (string) ($msg['intent'] ?? 'directive'),
                'facts' => is_array($msg['facts'] ?? null) ? $msg['facts'] : [],
            ];
        }

        return $normalized;
    }

    /**
     * Convert human date string to sortable integer YYYYMMDD.
     */
    protected function parseDateToSortable(?string $dateStr, int $fallbackIndex = 0): int
    {
        if (empty($dateStr)) {
            return 20260100 + $fallbackIndex;
        }

        $clean = trim($dateStr);
        if (preg_match('/(\d{1,2})\s+([a-zA-Z]{3,9})/i', $clean, $matches)) {
            $day = (int) $matches[1];
            $monthName = strtolower(substr($matches[2], 0, 3));
            $months = [
                'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
                'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
                'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
            ];
            $month = $months[$monthName] ?? 11;

            return (2026 * 10000) + ($month * 100) + $day;
        }

        $timestamp = strtotime($dateStr);
        if ($timestamp !== false) {
            return (int) date('Ymd', $timestamp);
        }

        return 20260100 + $fallbackIndex;
    }
}
