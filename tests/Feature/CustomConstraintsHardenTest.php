<?php

use App\Models\IngestedMessage;
use App\Models\PlanState;
use App\Services\ConstraintSolverService;
use App\Services\MessageReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('MessageReaderService prompt includes custom_constraints schema and guideline', function () {
    $reader = app(MessageReaderService::class);
    $reflection = new ReflectionClass($reader);
    $method = $reflection->getMethod('buildSystemPrompt');
    $method->setAccessible(true);
    $prompt = $method->invoke($reader);

    expect($prompt)->toContain('custom_constraints')
        ->toContain('"rule_type": "min|max|required|forbidden|ratio"')
        ->toContain('Explicitly capture ANY domain constraint, requirement, exclusion, dietary rule');
});

test('ConstraintSolverService enforces required venue feature and rejects update if no venue satisfies it', function () {
    $solver = app(ConstraintSolverService::class);
    $state = PlanState::create(['status' => 'initialized']);

    $batch1 = [
        [
            'raw_index' => '1',
            'sender' => 'Organizer',
            'date_str' => '1 Nov',
            'normalized_date_sort' => 20261101,
            'raw_content' => '100 students in teams of 4, 10 staff. Budget 400,000.',
            'intent' => 'directive',
            'facts' => [
                'students_count' => 100,
                'team_size' => 4,
                'staff_count' => 10,
                'total_budget_limit' => 400000,
                'judging_rules' => [
                    'confirmed_judges' => 10,
                    'max_teams_per_judge' => 3,
                    'honorarium_per_judge' => 5000,
                ],
            ],
        ],
        [
            'raw_index' => '2',
            'sender' => 'Halls',
            'date_str' => '2 Nov',
            'normalized_date_sort' => 20261102,
            'raw_content' => 'Hall 1 seats 150 at Rs 100,000. Hall 2 seats 150 at Rs 120,000 with standby generator.',
            'intent' => 'vendor_quote',
            'facts' => [
                'venue_options' => [
                    ['name' => 'Hall 1', 'capacity' => 150, 'cost' => 100000, 'features' => ['ac']],
                    ['name' => 'Hall 2', 'capacity' => 150, 'cost' => 120000, 'features' => ['ac', 'standby generator']],
                ],
                'catering_options' => [
                    ['name' => 'Standard', 'cost_per_head' => 500, 'delivery_cost' => 0],
                ],
            ],
        ],
    ];

    $result1 = $solver->processAndValidate($state, $batch1, 1);
    expect($result1['status'])->toBe('success');
    $state->refresh();

    // Now send Batch 2 requiring a feature that no venue has (e.g. "wheelchair ramp")
    $batch2Invalid = [
        [
            'raw_index' => '3',
            'sender' => 'Safety Officer',
            'date_str' => '3 Nov',
            'normalized_date_sort' => 20261103,
            'raw_content' => 'Mandatory update: Wheelchair ramp access is required.',
            'intent' => 'constraint_update',
            'facts' => [
                'custom_constraints' => [
                    [
                        'area' => 'venue',
                        'rule_type' => 'required',
                        'target' => 'wheelchair_ramp',
                        'value' => 'wheelchair_ramp',
                        'description' => 'Mandatory wheelchair ramp access',
                    ],
                ],
            ],
        ],
    ];

    $result2 = $solver->processAndValidate($state, $batch2Invalid, 2);
    expect($result2['status'])->toBe('rejected')
        ->and($result2['rejection_reason'])->toContain('Required venue feature')
        ->and($result2['rejection_reason'])->toContain('wheelchair_ramp')
        ->and($result2['affected_areas'])->toBeEmpty();

    // Verify existing PlanState was untouched
    $state->refresh();
    expect($state->extracted_facts['computed']['selected_venue']['name'])->toBe('Hall 2');

    $rejectedMsg = IngestedMessage::where('batch_number', 2)->first();
    expect($rejectedMsg->status)->toBe('rejected')
        ->and($rejectedMsg->rejection_reason)->toContain('wheelchair_ramp');
});

test('ConstraintSolverService enforces category budget caps against updates', function () {
    $solver = app(ConstraintSolverService::class);
    $state = PlanState::create(['status' => 'initialized']);

    $batch1 = [
        [
            'raw_index' => '1',
            'sender' => 'Finance Chair',
            'date_str' => '1 Nov',
            'normalized_date_sort' => 20261101,
            'raw_content' => '100 students, 10 staff. Printing budget cannot exceed Rs 10,000.',
            'intent' => 'directive',
            'facts' => [
                'students_count' => 100,
                'team_size' => 4,
                'staff_count' => 10,
                'total_budget_limit' => 500000,
                'fixed_expenses' => [
                    ['item' => 'printing', 'cost' => 8000, 'is_approved' => true],
                ],
                'custom_constraints' => [
                    [
                        'area' => 'budget',
                        'rule_type' => 'max',
                        'target' => 'printing',
                        'value' => 10000,
                        'description' => 'Printing budget cap',
                    ],
                ],
                'venue_options' => [
                    ['name' => 'Hall 1', 'capacity' => 150, 'cost' => 100000, 'features' => []],
                ],
                'catering_options' => [
                    ['name' => 'Standard', 'cost_per_head' => 500, 'delivery_cost' => 0],
                ],
                'judging_rules' => [
                    'confirmed_judges' => 10,
                    'max_teams_per_judge' => 3,
                    'honorarium_per_judge' => 3000,
                ],
            ],
        ],
    ];

    $res1 = $solver->processAndValidate($state, $batch1, 1);
    expect($res1['status'])->toBe('success');
    $state->refresh();

    // Update with printing cost 15,000 (exceeds cap of 10,000)
    $batch2OverCap = [
        [
            'raw_index' => '2',
            'sender' => 'Vendor',
            'date_str' => '2 Nov',
            'normalized_date_sort' => 20261102,
            'raw_content' => 'Printing invoice is now Rs 15,000.',
            'intent' => 'constraint_update',
            'facts' => [
                'fixed_expenses' => [
                    ['item' => 'printing', 'cost' => 15000, 'is_approved' => true],
                ],
            ],
        ],
    ];

    $res2 = $solver->processAndValidate($state, $batch2OverCap, 2);
    expect($res2['status'])->toBe('rejected')
        ->and($res2['rejection_reason'])->toContain('printing')
        ->and($res2['rejection_reason'])->toContain('exceeds maximum category budget cap');

    // Verify existing PlanState preserved
    $state->refresh();
    $printingExp = collect($state->extracted_facts['fixed_expenses'])->firstWhere('item', 'printing');
    expect((int) $printingExp['cost'])->toBe(8000);
});

test('ConstraintSolverService enforces minimum break duration and judge workload limits', function () {
    $solver = app(ConstraintSolverService::class);
    $state = PlanState::create(['status' => 'initialized']);

    $batch1 = [
        [
            'raw_index' => '1',
            'sender' => 'Organizer',
            'date_str' => '1 Nov',
            'normalized_date_sort' => 20261101,
            'raw_content' => 'Event setup with strict 30 minute minimum prayer break and max 2 teams per judge.',
            'intent' => 'directive',
            'facts' => [
                'students_count' => 90,
                'team_size' => 3, // 30 teams
                'staff_count' => 10,
                'total_budget_limit' => 500000,
                'schedule_constraints' => [
                    ['type' => 'prayer_break', 'description' => 'Prayer break', 'duration_minutes' => 30, 'delay_minutes' => 0],
                ],
                'custom_constraints' => [
                    [
                        'area' => 'schedule',
                        'rule_type' => 'min',
                        'target' => 'prayer_break',
                        'value' => 30,
                        'description' => 'Minimum 30 mins prayer break required',
                    ],
                    [
                        'area' => 'judging',
                        'rule_type' => 'max',
                        'target' => 'teams_per_judge',
                        'value' => 2,
                        'description' => 'Max 2 teams per judge',
                    ],
                ],
                'venue_options' => [
                    ['name' => 'Hall 1', 'capacity' => 150, 'cost' => 100000, 'features' => []],
                ],
                'catering_options' => [
                    ['name' => 'Standard', 'cost_per_head' => 500, 'delivery_cost' => 0],
                ],
                'judging_rules' => [
                    'confirmed_judges' => 15, // 30 teams / 15 judges = 2 teams/judge (ok)
                    'max_teams_per_judge' => 2,
                    'honorarium_per_judge' => 2000,
                ],
            ],
        ],
    ];

    $res1 = $solver->processAndValidate($state, $batch1, 1);
    expect($res1['status'])->toBe('success');
    $state->refresh();

    // Batch 2: Attempting to cut prayer break to 15 mins (violates min 30 mins)
    $batch2BreakViolation = [
        [
            'raw_index' => '2',
            'sender' => 'Timekeeper',
            'date_str' => '2 Nov',
            'normalized_date_sort' => 20261102,
            'raw_content' => 'Shortening prayer break to 15 mins.',
            'intent' => 'constraint_update',
            'facts' => [
                'schedule_constraints' => [
                    ['type' => 'prayer_break', 'description' => 'Prayer break', 'duration_minutes' => 15, 'delay_minutes' => 0],
                ],
            ],
        ],
    ];

    $res2 = $solver->processAndValidate($state, $batch2BreakViolation, 2);
    expect($res2['status'])->toBe('rejected')
        ->and($res2['rejection_reason'])->toContain('Break duration (15 mins) is less than required minimum of 30 mins');
});
