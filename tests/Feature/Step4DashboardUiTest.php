<?php

use App\Models\LlmTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(function (Request $request) {
        $messages = $request['messages'] ?? [];
        $systemContent = '';
        $userContent = '';

        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $systemContent = $m['content'] ?? '';
            }
            if (($m['role'] ?? '') === 'user') {
                $userContent = $m['content'] ?? '';
            }
        }

        if (str_contains($systemContent, 'extraction agent')) {
            if (str_contains($userContent, 'rounded') || str_contains($userContent, '100 boxes')) {
                return Http::response([
                    'model' => 'grok-2-latest',
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => json_encode([
                                    'messages' => [
                                        [
                                            'raw_index' => 'trap-1',
                                            'sender' => 'Caterer',
                                            'date_str' => '16 Nov',
                                            'normalized_date_sort' => 20261116,
                                            'raw_content' => 'Caterer: I have rounded your order down to 100 boxes to keep the cost low.',
                                            'is_unapproved_wish' => false,
                                            'intent' => 'forced_override',
                                            'facts' => [
                                                'catering_options' => [
                                                    ['name' => 'Standard lunch box', 'forced_box_count' => 100],
                                                ],
                                            ],
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                    'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160],
                ], 200);
            }

            return Http::response([
                'model' => 'grok-2-latest',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'messages' => [
                                    [
                                        'raw_index' => '1',
                                        'sender' => "Dean's office",
                                        'date_str' => '9 Nov',
                                        'normalized_date_sort' => 20261109,
                                        'raw_content' => 'Approved: a 4-hour hackathon for 150 students in teams of 3. Budget is Rs 650,000. 10 staff.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'directive',
                                        'facts' => [
                                            'duration_hours' => 4,
                                            'students_count' => 150,
                                            'team_size' => 3,
                                            'staff_count' => 10,
                                            'total_budget_limit' => 650000,
                                        ],
                                    ],
                                    [
                                        'raw_index' => '2',
                                        'sender' => 'Margalla Event Halls',
                                        'date_str' => '3 Nov',
                                        'normalized_date_sort' => 20261103,
                                        'raw_content' => 'Hall B seats 200 at Rs 150,000 with standby generator.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'vendor_quote',
                                        'facts' => [
                                            'venue_options' => [
                                                ['name' => 'Hall B', 'capacity' => 200, 'cost' => 150000, 'features' => ['standby generator']],
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '4',
                                        'sender' => 'Caterer',
                                        'date_str' => '12 Nov',
                                        'normalized_date_sort' => 20261112,
                                        'raw_content' => 'Standard lunch box Rs 1,200 per head.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'vendor_quote',
                                        'facts' => [
                                            'catering_options' => [
                                                ['name' => 'Standard lunch box', 'cost_per_head' => 1200, 'delivery_cost' => 0],
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '5',
                                        'sender' => 'Judging coordinator',
                                        'date_str' => '11 Nov',
                                        'normalized_date_sort' => 20261111,
                                        'raw_content' => '20 judges confirmed. Honorarium Rs 5,000 each. Max 3 teams per judge.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'vendor_quote',
                                        'facts' => [
                                            'judging_rules' => [
                                                'confirmed_judges' => 20,
                                                'honorarium_per_judge' => 5000,
                                                'max_teams_per_judge' => 3,
                                            ],
                                        ],
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 250, 'total_tokens' => 750],
            ], 200);
        }

        if (str_contains($systemContent, 'venue planner')) {
            return Http::response([
                'model' => 'grok-2-latest',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'selected_venue' => 'Hall B',
                                'capacity' => 200,
                                'required_headcount' => 177,
                                'cost' => 150000,
                                'features' => ['standby generator'],
                                'justification' => 'Selected within budget.',
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130],
            ], 200);
        }

        if (str_contains($systemContent, 'schedule planner')) {
            return Http::response([
                'model' => 'grok-2-latest',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'total_duration_minutes' => 240,
                                'timeline' => [
                                    ['time_slot' => '00:00 - 04:00', 'activity' => 'Hackathon', 'duration_minutes' => 240, 'notes' => 'Full event'],
                                ],
                                'constraints_honored' => ['240 mins'],
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130],
            ], 200);
        }

        if (str_contains($systemContent, 'catering planner')) {
            return Http::response([
                'model' => 'grok-2-latest',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'selected_package' => 'Standard lunch box',
                                'headcount_breakdown' => [
                                    'students' => 150,
                                    'staff' => 10,
                                    'judges' => 17,
                                    'total' => 177,
                                ],
                                'unit_cost' => 1200,
                                'delivery_cost' => 0,
                                'total_food_cost' => 212400,
                                'notes' => 'Free delivery',
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130],
            ], 200);
        }

        if (str_contains($systemContent, 'judging coordinator')) {
            return Http::response([
                'model' => 'grok-2-latest',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'total_teams' => 50,
                                'confirmed_judges' => 20,
                                'assigned_judges' => 17,
                                'standby_judges' => 3,
                                'max_teams_per_judge' => 3,
                                'minutes_per_team' => 15,
                                'honorarium_per_judge' => 5000,
                                'total_judging_cost' => 85000,
                                'contingency_notes' => '3 standby judges',
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130],
            ], 200);
        }

        if (str_contains($systemContent, 'budget planner')) {
            return Http::response([
                'model' => 'grok-2-latest',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'budget_limit' => 650000,
                                'line_items' => [
                                    ['category' => 'Venue', 'description' => 'Hall B', 'amount' => 150000],
                                    ['category' => 'Food', 'description' => 'Catering', 'amount' => 212400],
                                    ['category' => 'Judging', 'description' => 'Judges', 'amount' => 85000],
                                ],
                                'total_spent' => 447400,
                                'remaining_balance' => 202600,
                                'within_budget' => true,
                                'excluded_items' => [],
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130],
            ], 200);
        }

        return Http::response(['choices' => [['message' => ['content' => '{}']]]], 200);
    });
});

test('GET / returns HTTP 200 and renders Dashboard Inertia component with required props', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('planState')
        ->has('messages')
        ->has('traces')
        ->has('totalTokens')
        ->has('rawTraceText')
        ->has('rawPlanText')
    );
});

test('after running sample pack and invalid trap, GET / contains rejected message reason and accurate cumulative token count', function () {
    // 1. Run sample pack
    $this->artisan('plan:run', ['file' => 'storage/app/samples/sample_pack.txt'])
        ->assertExitCode(0);

    // 2. Run invalid update trap
    $this->artisan('plan:run', ['file' => 'storage/app/samples/update_invalid.txt'])
        ->assertExitCode(0);

    // 3. Request dashboard
    $response = $this->get('/');
    $response->assertOk();

    $expectedTokens = (int) LlmTrace::sum('total_tokens');
    expect($expectedTokens)->toBeGreaterThan(0);

    $response->assertInertia(function (Assert $page) use ($expectedTokens) {
        $page->component('dashboard')
            ->where('totalTokens', $expectedTokens)
            ->has('messages', 5) // 4 accepted from initial pack + 1 rejected trap
            ->where('messages.0.status', 'rejected')
            ->where('messages.0.rejection_reason', fn ($reason) => str_contains($reason, '100') && str_contains($reason, '177'));
    });
});

test('GET /export/submission downloads the combined plan and trace text file', function () {
    $this->artisan('plan:run', ['file' => 'storage/app/samples/sample_pack.txt'])
        ->assertExitCode(0);

    $response = $this->get('/export/submission');

    $response->assertOk();
    $response->assertHeader('Content-Disposition', 'attachment; filename=submission.txt');

    $content = $response->streamedContent();
    expect($content)->toContain('HACKATHON EVENT PLAN')
        ->toContain('Call #1')
        ->toContain('Total Tokens:');
});
