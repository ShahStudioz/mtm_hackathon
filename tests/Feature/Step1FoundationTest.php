<?php

use App\Enums\PlanArea;
use App\Models\LlmTrace;
use App\Models\PlanState;
use App\Services\LlmClientService;
use App\Services\TraceFormatterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('PlanArea enum contains strictly the required six values', function () {
    expect(PlanArea::values())->toEqualCanonicalizing([
        'reading',
        'venue',
        'schedule',
        'food',
        'judging',
        'budget',
    ]);
});

test('LlmClientService sends request, extracts model and exact usage tokens, and saves an LlmTrace', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'chatcmpl-test-1',
            'model' => 'grok-2-custom-123',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"result": "extracted_facts"}',
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 142,
                'completion_tokens' => 58,
                'total_tokens' => 200,
            ],
        ], 200),
    ]);

    $state = PlanState::create([
        'status' => 'initialized',
    ]);

    $service = new LlmClientService;
    $result = $service->call(
        state: $state,
        batchNumber: 1,
        area: PlanArea::Reading,
        systemPrompt: 'System instructions here',
        userPrompt: 'Extract facts from input',
        temperature: 0.1
    );

    expect($result)->toHaveKeys(['content', 'data', 'raw_content', 'trace'])
        ->and($result['data'])->toBe(['result' => 'extracted_facts'])
        ->and($result['trace'])->toBeInstanceOf(LlmTrace::class);

    $this->assertDatabaseHas('llm_traces', [
        'plan_state_id' => $state->id,
        'batch_number' => 1,
        'call_number' => 1,
        'model_name' => 'grok-2-custom-123',
        'area' => 'reading',
        'prompt_tokens' => 142,
        'completion_tokens' => 58,
        'total_tokens' => 200,
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/chat/completions')
            && $request['messages'][0]['content'] === 'System instructions here'
            && $request['messages'][1]['content'] === 'Extract facts from input'
            && $request['temperature'] === 0.1;
    });
});

test('sequential calls increment call_number properly (1, 2, 3)', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'grok-2-latest',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"step": "ok"}',
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
            ],
        ], 200),
    ]);

    $state = PlanState::create();
    $service = new LlmClientService;

    $res1 = $service->call($state, 1, PlanArea::Reading, 'Sys', 'User 1');
    $res2 = $service->call($state, 1, PlanArea::Venue, 'Sys', 'User 2');
    $res3 = $service->call($state, 1, PlanArea::Schedule, 'Sys', 'User 3');

    expect($res1['trace']->call_number)->toBe(1)
        ->and($res2['trace']->call_number)->toBe(2)
        ->and($res3['trace']->call_number)->toBe(3);

    expect($state->llmTraces()->pluck('call_number')->all())->toBe([1, 2, 3]);
});

test('TraceFormatterService prints every required field and finishes strictly with cumulative tokens', function () {
    $state = PlanState::create();

    $trace1 = $state->llmTraces()->create([
        'batch_number' => 1,
        'call_number' => 1,
        'model_name' => 'grok-2-latest',
        'area' => PlanArea::Reading,
        'input_payload' => 'System: System prompt\nUser: Read inputs',
        'output_payload' => '{"headcount": 100}',
        'prompt_tokens' => 100,
        'completion_tokens' => 30,
        'total_tokens' => 130,
    ]);

    $trace2 = $state->llmTraces()->create([
        'batch_number' => 1,
        'call_number' => 2,
        'model_name' => 'grok-2-latest',
        'area' => PlanArea::Budget,
        'input_payload' => 'System: Check finance\nUser: Calculate limits',
        'output_payload' => '{"allocated": 50000}',
        'prompt_tokens' => 150,
        'completion_tokens' => 40,
        'total_tokens' => 190,
    ]);

    $state->ingestedMessages()->create([
        'batch_number' => 1,
        'raw_index' => '7',
        'sender' => 'Student society',
        'message_date' => '13 Nov',
        'raw_content' => 'Can we get a DJ for the closing? The quote is Rs 50,000.',
        'status' => 'rejected',
        'rejection_reason' => 'DJ quote exceeds discretionary budget limit.',
    ]);

    $formatter = new TraceFormatterService;
    $output = $formatter->formatTrace($state);

    expect($output)->toContain('Call #1')
        ->toContain('Call #2')
        ->toContain('Model: grok-2-latest')
        ->toContain('Area: reading')
        ->toContain('Area: budget')
        ->toContain('Input: System: System prompt\nUser: Read inputs')
        ->toContain('Output: {"headcount": 100}')
        ->toContain('Tokens: prompt=100, completion=30, total=130')
        ->toContain('Tokens: prompt=150, completion=40, total=190')
        ->toContain('Rejected Messages:')
        ->toContain('DJ quote exceeds discretionary budget limit.')
        ->toContain('Message #7 (Student society)');

    $lines = explode("\n", trim($output));
    $lastLine = end($lines);

    expect($lastLine)->toBe('Total Tokens: 320');
});
