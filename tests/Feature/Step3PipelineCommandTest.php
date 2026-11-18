<?php

use App\Enums\PlanArea;
use App\Models\IngestedMessage;
use App\Models\LlmTrace;
use App\Models\PlanState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

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

        // 1. Reading Call
        if (str_contains($systemContent, 'extraction agent')) {
            // Update valid (printing bill)
            if (str_contains($userContent, 'printing bill')) {
                return Http::response([
                    'model' => 'grok-2-latest',
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => json_encode([
                                    'messages' => [
                                        [
                                            'raw_index' => 'update-1',
                                            'sender' => 'Finance',
                                            'date_str' => '15 Nov',
                                            'normalized_date_sort' => 20261115,
                                            'raw_content' => 'Finance here. The printing bill came in at Rs 15,000, not Rs 10,000.',
                                            'is_unapproved_wish' => false,
                                            'intent' => 'constraint_update',
                                            'facts' => [
                                                'fixed_expenses' => [
                                                    ['item' => 'printing', 'cost' => 15000, 'is_approved' => true],
                                                ],
                                            ],
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                    'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 50, 'total_tokens' => 200],
                ], 200);
            }

            // Update invalid trap (100 boxes)
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

            // Initial Sample Pack (8 messages)
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
                                        'raw_content' => 'Approved: a 4-hour hackathon for 150 students in teams of 3. The budget is Rs 650,000, all in. Ten staff will be on duty. Please keep a 15-minute prayer break in the schedule.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'directive',
                                        'facts' => [
                                            'duration_hours' => 4,
                                            'students_count' => 150,
                                            'team_size' => 3,
                                            'staff_count' => 10,
                                            'total_budget_limit' => 650000,
                                            'schedule_constraints' => [
                                                ['type' => 'prayer_break', 'description' => '15-minute prayer break', 'duration_minutes' => 15, 'delay_minutes' => 0],
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '2',
                                        'sender' => 'Margalla Event Halls',
                                        'date_str' => '3 Nov',
                                        'normalized_date_sort' => 20261103,
                                        'raw_content' => 'Room D seats 80 at Rs 40,000. Hall A seats 200 at Rs 120,000. Hall B seats 200 at Rs 150,000. Hall C seats 300 at Rs 220,000. Halls B and C have a standby generator.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'vendor_quote',
                                        'facts' => [
                                            'venue_options' => [
                                                ['name' => 'Room D', 'capacity' => 80, 'cost' => 40000, 'features' => []],
                                                ['name' => 'Hall A', 'capacity' => 200, 'cost' => 120000, 'features' => []],
                                                ['name' => 'Hall B', 'capacity' => 200, 'cost' => 150000, 'features' => ['standby generator']],
                                                ['name' => 'Hall C', 'capacity' => 300, 'cost' => 220000, 'features' => ['standby generator']],
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '3',
                                        'sender' => 'Caterer',
                                        'date_str' => '20 Oct',
                                        'normalized_date_sort' => 20261020,
                                        'raw_content' => 'Standard lunch box Rs 1,000 per head.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'vendor_quote',
                                        'facts' => [
                                            'catering_options' => [
                                                ['name' => 'Standard lunch box', 'cost_per_head' => 1000, 'delivery_cost' => 0],
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '4',
                                        'sender' => 'Caterer',
                                        'date_str' => '12 Nov',
                                        'normalized_date_sort' => 20261112,
                                        'raw_content' => 'Salam bhai! Standard lunch box ab Rs 1,200 per head hoga, basic wala Rs 800. Delivery free hai.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'vendor_quote',
                                        'facts' => [
                                            'catering_options' => [
                                                ['name' => 'Standard lunch box', 'cost_per_head' => 1200, 'delivery_cost' => 0],
                                                ['name' => 'Basic lunch box', 'cost_per_head' => 800, 'delivery_cost' => 0],
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '5',
                                        'sender' => 'Judging coordinator',
                                        'date_str' => '11 Nov',
                                        'normalized_date_sort' => 20261111,
                                        'raw_content' => '20 judges have confirmed. The honorarium is Rs 5,000 each. A judge needs 15 minutes per team and should cover no more than three teams.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'vendor_quote',
                                        'facts' => [
                                            'judging_rules' => [
                                                'confirmed_judges' => 20,
                                                'honorarium_per_judge' => 5000,
                                                'minutes_per_team' => 15,
                                                'max_teams_per_judge' => 3,
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '6',
                                        'sender' => 'Finance',
                                        'date_str' => '10 Nov',
                                        'normalized_date_sort' => 20261110,
                                        'raw_content' => 'Already ordered from the event budget: prizes Rs 100,000, backup internet Rs 20,000, printing Rs 10,000.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'directive',
                                        'facts' => [
                                            'fixed_expenses' => [
                                                ['item' => 'prizes', 'cost' => 100000, 'is_approved' => true],
                                                ['item' => 'backup internet', 'cost' => 20000, 'is_approved' => true],
                                                ['item' => 'printing', 'cost' => 10000, 'is_approved' => true],
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '7',
                                        'sender' => 'Student society',
                                        'date_str' => '13 Nov',
                                        'normalized_date_sort' => 20261113,
                                        'raw_content' => 'Can we get a DJ for the closing? The quote is Rs 50,000.',
                                        'is_unapproved_wish' => true,
                                        'intent' => 'unapproved_request',
                                        'facts' => [
                                            'fixed_expenses' => [
                                                ['item' => 'DJ', 'cost' => 50000, 'is_approved' => false],
                                            ],
                                        ],
                                    ],
                                    [
                                        'raw_index' => '8',
                                        'sender' => 'A judge',
                                        'date_str' => '14 Nov',
                                        'normalized_date_sort' => 20261114,
                                        'raw_content' => 'I may be 10 minutes late on the day. Traffic on Srinagar Highway.',
                                        'is_unapproved_wish' => false,
                                        'intent' => 'constraint_update',
                                        'facts' => [
                                            'schedule_constraints' => [
                                                ['type' => 'judge_delay', 'description' => 'A judge 10 minutes late', 'duration_minutes' => 0, 'delay_minutes' => 10],
                                            ],
                                        ],
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 600, 'completion_tokens' => 300, 'total_tokens' => 900],
            ], 200);
        }

        // 2. Venue Call
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
                                'justification' => 'Hall B fits 177 attendees comfortably and has standby generator within budget.',
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40, 'total_tokens' => 140],
            ], 200);
        }

        // 3. Schedule Call
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
                                    ['time_slot' => '00:00 - 00:30', 'activity' => 'Kickoff & Registration', 'duration_minutes' => 30, 'notes' => 'Setup'],
                                    ['time_slot' => '00:30 - 02:00', 'activity' => 'Hacking Phase 1', 'duration_minutes' => 90, 'notes' => 'Sprint'],
                                    ['time_slot' => '02:00 - 02:15', 'activity' => 'Prayer Break', 'duration_minutes' => 15, 'notes' => '15m prayer break'],
                                    ['time_slot' => '02:15 - 03:00', 'activity' => 'Hacking Phase 2', 'duration_minutes' => 45, 'notes' => 'Buffer accommodates late judge'],
                                    ['time_slot' => '03:00 - 03:45', 'activity' => 'Judging Sessions', 'duration_minutes' => 45, 'notes' => 'Parallel evaluation'],
                                    ['time_slot' => '03:45 - 04:00', 'activity' => 'Closing Ceremony', 'duration_minutes' => 15, 'notes' => 'Prizes'],
                                ],
                                'constraints_honored' => ['15-minute prayer break', 'Judge delay buffer', 'Total 240 minutes'],
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 80, 'total_tokens' => 230],
            ], 200);
        }

        // 4. Food Call
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
                                'notes' => 'Standard lunch box selected with free delivery.',
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 110, 'completion_tokens' => 50, 'total_tokens' => 160],
            ], 200);
        }

        // 5. Judging Call
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
                                'contingency_notes' => '3 standby judges ready; late judge delay absorbed in schedule.',
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 130, 'completion_tokens' => 60, 'total_tokens' => 190],
            ], 200);
        }

        // 6. Budget Call
        if (str_contains($systemContent, 'budget planner')) {
            $printingAmount = str_contains($userContent, '15000') ? 15000 : 10000;
            $totalSpent = str_contains($userContent, '15000') ? 582400 : 577400;
            $remaining = 650000 - $totalSpent;

            return Http::response([
                'model' => 'grok-2-latest',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'budget_limit' => 650000,
                                'line_items' => [
                                    ['category' => 'Venue', 'description' => 'Hall B (seats 200, generator)', 'amount' => 150000],
                                    ['category' => 'Catering', 'description' => '177 Standard lunch boxes', 'amount' => 212400],
                                    ['category' => 'Judging', 'description' => '17 Judges honorarium (Rs 5,000 each)', 'amount' => 85000],
                                    ['category' => 'Prizes', 'description' => 'Approved prizes', 'amount' => 100000],
                                    ['category' => 'Backup Internet', 'description' => 'Backup internet connection', 'amount' => 20000],
                                    ['category' => 'Printing', 'description' => 'Event printing', 'amount' => $printingAmount],
                                ],
                                'total_spent' => $totalSpent,
                                'remaining_balance' => $remaining,
                                'within_budget' => true,
                                'excluded_items' => [
                                    ['item' => 'DJ', 'cost' => 50000, 'reason' => 'Unapproved optional expense request (DJ) outside core hackathon requirements'],
                                ],
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 180, 'completion_tokens' => 90, 'total_tokens' => 270],
            ], 200);
        }

        return Http::response(['choices' => [['message' => ['content' => '{}']]]], 200);
    });
});

test('end-to-end pipeline executes initial pack, valid update, and catches invalid trap with exact token formatting', function () {
    // -------------------------------------------------------------------------
    // 1. Initial Sample Pack Run
    // -------------------------------------------------------------------------
    $this->artisan('plan:run', ['file' => 'storage/app/samples/sample_pack.txt'])
        ->assertExitCode(0);

    // Exactly 6 LLM calls recorded in Batch #1: reading, venue, schedule, food, judging, budget
    $batch1Traces = LlmTrace::where('batch_number', 1)->orderBy('call_number')->get();
    expect($batch1Traces)->toHaveCount(6);

    $batch1Areas = $batch1Traces->map(fn ($t) => $t->area->value)->all();
    expect($batch1Areas)->toBe(['reading', 'venue', 'schedule', 'food', 'judging', 'budget']);

    // Check PlanState has all 5 plans populated and validation summary passes
    $state = PlanState::latest()->first();
    expect($state)->not->toBeNull();
    expect($state->venue_plan)->not->toBeNull();
    expect($state->schedule_plan)->not->toBeNull();
    expect($state->food_plan)->not->toBeNull();
    expect($state->judging_plan)->not->toBeNull();
    expect($state->budget_plan)->not->toBeNull();

    expect($state->validation_summary['correct_figures'])->toBeTrue();
    expect($state->validation_summary['within_budget'])->toBeTrue();
    expect($state->validation_summary['venue_fits'])->toBeTrue();
    expect($state->validation_summary['schedule_valid'])->toBeTrue();
    expect($state->validation_summary['enough_judges'])->toBeTrue();
    expect($state->validation_summary['all_areas_covered'])->toBeTrue();

    // Verify messages: Message 3 superseded, Message 7 rejected, Message 8 accepted (delay notice)
    expect(IngestedMessage::where('plan_state_id', $state->id)->where('raw_index', '3')->first()->status)->toBe('superseded');
    expect(IngestedMessage::where('plan_state_id', $state->id)->where('raw_index', '7')->first()->status)->toBe('rejected');
    expect(IngestedMessage::where('plan_state_id', $state->id)->where('raw_index', '8')->first()->status)->toBe('accepted');

    // Verify files created
    expect(File::exists(storage_path('app/output/final_plan.txt')))->toBeTrue();
    expect(File::exists(storage_path('app/output/full_trace.txt')))->toBeTrue();
    expect(File::exists(storage_path('app/output/submission.txt')))->toBeTrue();

    // -------------------------------------------------------------------------
    // 2. Valid Update Run (Printing bill Rs 15,000)
    // -------------------------------------------------------------------------
    $this->artisan('plan:run', ['file' => 'storage/app/samples/update_valid.txt'])
        ->assertExitCode(0);

    // Exactly 2 new calls in Batch #2: reading and budget
    $batch2Traces = LlmTrace::where('batch_number', 2)->orderBy('call_number')->get();
    expect($batch2Traces)->toHaveCount(2);

    $batch2Areas = $batch2Traces->map(fn ($t) => $t->area->value)->all();
    expect($batch2Areas)->toBe(['reading', 'budget']);

    // budget_plan reflects updated amount while other plans remain untouched
    $state->refresh();
    $printingItem = collect($state->budget_plan['line_items'])->firstWhere('category', 'Printing');
    expect($printingItem)->not->toBeNull();
    expect($printingItem['amount'])->toBe(15000);

    // -------------------------------------------------------------------------
    // 3. Invalid Update Trap Run (Caterer 100 boxes)
    // -------------------------------------------------------------------------
    $this->artisan('plan:run', ['file' => 'storage/app/samples/update_invalid.txt'])
        ->assertExitCode(0);

    // Exactly 1 new call in Batch #3: reading ONLY (zero area planner calls!)

    $batch3Traces = LlmTrace::where('batch_number', 3)->orderBy('call_number')->get();
    expect($batch3Traces)->toHaveCount(1);
    expect($batch3Traces[0]->area->value)->toBe('reading');

    // Check full_trace.txt on disk
    $traceContent = File::get(storage_path('app/output/full_trace.txt'));
    expect($traceContent)->toContain('100')
        ->toContain('177');

    // Final line strictly ends with cumulative tokens
    $lines = explode("\n", trim($traceContent));
    $lastLine = end($lines);
    expect($lastLine)->toMatch('/^Total Tokens: \d+$/');
});

test('plan:reset clears all states, ingested messages, and traces', function () {
    $state = PlanState::create();
    $state->llmTraces()->create([
        'batch_number' => 1,
        'call_number' => 1,
        'model_name' => 'test-model',
        'area' => PlanArea::Reading,
        'input_payload' => 'in',
        'output_payload' => 'out',
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'total_tokens' => 15,
    ]);

    $this->artisan('plan:reset')
        ->assertExitCode(0)
        ->expectsOutput('Plan database cleanly reset.');

    expect(PlanState::count())->toBe(0)
        ->and(LlmTrace::count())->toBe(0);
});
