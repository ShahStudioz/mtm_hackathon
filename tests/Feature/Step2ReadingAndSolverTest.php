<?php

use App\Models\IngestedMessage;
use App\Models\PlanState;
use App\Services\ConstraintSolverService;
use App\Services\MessageReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('initial sample pack extracts facts, computes total headcount as 177, supersedes old quotes, rejects unapproved wishes, and validates within budget', function () {
    $sampleParsedMessages = [
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
    ];

    $state = PlanState::create();
    $solver = app(ConstraintSolverService::class);
    $result = $solver->processAndValidate($state, $sampleParsedMessages, 1);

    expect($result['status'])->toBe('success');
    expect($result['computed']['total_headcount'])->toBe(177);
    expect($result['computed']['teams_count'])->toBe(50);
    expect($result['computed']['required_judges'])->toBe(17);
    expect($result['computed']['total_cost'])->toBeLessThanOrEqual(650000);

    // Message 3 (20 Oct, Rs 1000) is marked superseded by Message 4 (12 Nov)
    $msg3 = IngestedMessage::where('plan_state_id', $state->id)->where('raw_index', '3')->first();
    expect($msg3)->not->toBeNull();
    expect($msg3->status)->toBe('superseded');
    expect($msg3->rejection_reason)->toContain('Superseded');

    $msg4 = IngestedMessage::where('plan_state_id', $state->id)->where('raw_index', '4')->first();
    expect($msg4)->not->toBeNull();
    expect($msg4->status)->toBe('accepted');

    // Message 7 (DJ Rs 50,000) is marked rejected as an unapproved wish
    $msg7 = IngestedMessage::where('plan_state_id', $state->id)->where('raw_index', '7')->first();
    expect($msg7)->not->toBeNull();
    expect($msg7->status)->toBe('rejected');
    expect($msg7->rejection_reason)->toContain('Unapproved');

    // Message 8 (Judge 10 min late notice) is an operational constraint and MUST be accepted
    $msg8 = IngestedMessage::where('plan_state_id', $state->id)->where('raw_index', '8')->first();
    expect($msg8)->not->toBeNull();
    expect($msg8->status)->toBe('accepted');
    expect($msg8->rejection_reason)->toBeNull();

    // All checks in validation_summary pass
    expect($result['validation_summary']['correct_figures'])->toBeTrue();
    expect($result['validation_summary']['within_budget'])->toBeTrue();
    expect($result['validation_summary']['venue_fits'])->toBeTrue();
    expect($result['validation_summary']['schedule_valid'])->toBeTrue();
    expect($result['validation_summary']['enough_judges'])->toBeTrue();
    expect($result['validation_summary']['all_areas_covered'])->toBeTrue();
});

test('MessageReaderService reads file and calls LLM client with PlanArea::Reading', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'chatcmpl-read-test',
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
                                    'raw_content' => 'Approved: a 4-hour hackathon for 150 students in teams of 3.',
                                    'is_unapproved_wish' => false,
                                    'intent' => 'directive',
                                    'facts' => [
                                        'duration_hours' => 4,
                                        'students_count' => 150,
                                        'team_size' => 3,
                                    ],
                                ],
                            ],
                        ]),
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 200,
                'completion_tokens' => 50,
                'total_tokens' => 250,
            ],
        ], 200),
    ]);

    $state = PlanState::create();
    $reader = app(MessageReaderService::class);
    $result = $reader->readFile($state, storage_path('app/samples/update_valid.txt'), 1);

    expect($result)->toHaveKeys(['messages', 'raw_content', 'trace']);
    expect($result['messages'])->toHaveCount(1);
    expect($result['messages'][0]['sender'])->toBe("Dean's office");

    $this->assertDatabaseHas('llm_traces', [
        'plan_state_id' => $state->id,
        'area' => 'reading',
        'prompt_tokens' => 200,
        'completion_tokens' => 50,
        'total_tokens' => 250,
    ]);
});

test('valid update performs surgical area detection returning strictly budget', function () {
    $state = PlanState::create();
    $solver = app(ConstraintSolverService::class);

    // Initial batch 1
    $initialMessages = [
        [
            'raw_index' => '1',
            'sender' => "Dean's office",
            'date_str' => '9 Nov',
            'normalized_date_sort' => 20261109,
            'raw_content' => '4-hour hackathon for 150 students in teams of 3. Budget Rs 650,000. 10 staff.',
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
        [
            'raw_index' => '6',
            'sender' => 'Finance',
            'date_str' => '10 Nov',
            'normalized_date_sort' => 20261110,
            'raw_content' => 'Prizes Rs 100,000, backup internet Rs 20,000, printing Rs 10,000.',
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
    ];

    $solver->processAndValidate($state, $initialMessages, 1);

    // Update batch 2: printing bill changed to 15,000
    $updateMessage = [
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
    ];

    $result = $solver->processAndValidate($state, $updateMessage, 2);

    expect($result['status'])->toBe('success');
    expect($result['affected_areas'])->toBe(['budget']);
    expect($result['validation_summary']['within_budget'])->toBeTrue();

    // Check that state extracted facts has printing updated to 15,000
    $state->refresh();
    $printingExp = collect($state->extracted_facts['fixed_expenses'])->firstWhere('item', 'printing');
    expect($printingExp)->not->toBeNull();
    expect($printingExp['cost'])->toBe(15000);
});

test('invalid update trap catches food under-ordering and keeps state intact with empty affected areas', function () {
    $state = PlanState::create();
    $solver = app(ConstraintSolverService::class);

    // Set up initial valid state
    $initialMessages = [
        [
            'raw_index' => '1',
            'sender' => "Dean's office",
            'date_str' => '9 Nov',
            'normalized_date_sort' => 20261109,
            'raw_content' => '4-hour hackathon for 150 students in teams of 3. Budget Rs 650,000. 10 staff.',
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
    ];

    $solver->processAndValidate($state, $initialMessages, 1);
    $initialFacts = $state->fresh()->extracted_facts;

    // Trap Update: Caterer rounding order down to 100 boxes
    $trapMessage = [
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
    ];

    $result = $solver->processAndValidate($state, $trapMessage, 2);

    expect($result['status'])->toBe('rejected');
    expect($result['affected_areas'])->toBe([]);
    expect($result['rejection_reason'])->toContain('100')->toContain('177');

    // Verify ingested message recorded with rejection
    $trapRecord = IngestedMessage::where('plan_state_id', $state->id)->where('batch_number', 2)->first();
    expect($trapRecord)->not->toBeNull();
    expect($trapRecord->status)->toBe('rejected');
    expect($trapRecord->rejection_reason)->toContain('100')->toContain('177');

    // Verify previous PlanState remained completely untouched
    $state->refresh();
    expect($state->extracted_facts)->toEqual($initialFacts);
});
