<?php

namespace App\Services;

use App\Models\IngestedMessage;
use App\Models\PlanState;

class ConstraintSolverService
{
    /**
     * Process extracted message facts, resolve chronological overrides,
     * detect conflicts/traps, perform deterministic math, and determine affected areas.
     *
     * @param  array<int, array<string, mixed>>|array{messages: array<int, array<string, mixed>>}  $parsedMessages
     * @return array{
     *     status: string,
     *     affected_areas: array<int, string>,
     *     computed?: array<string, mixed>,
     *     validation_summary?: array<string, bool>,
     *     rejection_reason?: string
     * }
     */
    public function processAndValidate(PlanState $state, array $parsedMessages, int $batchNumber): array
    {
        $messageList = isset($parsedMessages['messages']) && is_array($parsedMessages['messages'])
            ? $parsedMessages['messages']
            : $parsedMessages;

        if ($batchNumber === 1) {
            return $this->processInitialBatch($state, $messageList, $batchNumber);
        }

        return $this->processUpdateBatch($state, $messageList, $batchNumber);
    }

    /**
     * Process initial batch of messages (Batch 1).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    protected function processInitialBatch(PlanState $state, array $messages, int $batchNumber): array
    {
        $savedModels = [];

        // 1. Create IngestedMessage models
        foreach ($messages as $msg) {
            $savedModels[] = IngestedMessage::create([
                'plan_state_id' => $state->id,
                'batch_number' => $batchNumber,
                'raw_index' => isset($msg['raw_index']) ? (string) $msg['raw_index'] : null,
                'sender' => isset($msg['sender']) ? (string) $msg['sender'] : null,
                'message_date' => isset($msg['date_str']) ? (string) $msg['date_str'] : null,
                'raw_content' => (string) ($msg['raw_content'] ?? ''),
                'status' => 'accepted',
                'rejection_reason' => null,
                'affected_areas' => null,
            ]);
        }

        // 2. Identify unapproved wishes and irrelevant messages
        foreach ($messages as $idx => $msg) {
            $isUnapproved = ($msg['is_unapproved_wish'] ?? false) === true
                || ($msg['intent'] ?? '') === 'unapproved_request'
                || ($msg['intent'] ?? '') === 'irrelevant';

            if ($isUnapproved) {
                $item = $msg['facts']['fixed_expenses'][0]['item']
                    ?? (preg_match('/\b(dj|band|concert|party)\b/i', $msg['raw_content'] ?? '', $matches) ? strtoupper($matches[1]) : 'optional expense');

                $savedModels[$idx]->update([
                    'status' => 'rejected',
                    'rejection_reason' => "Rejected: Unapproved optional expense request ({$item}) outside core hackathon requirements",
                ]);
            }
        }

        // 3. Chronological sorting & superseding for quotes/options from same category
        $this->resolveChronologicalSuperseding($messages, $savedModels);

        // 4. Merge facts from all accepted messages
        $mergedFacts = $this->mergeAcceptedFacts($messages, $savedModels);

        // 5. Perform deterministic math & optimal selections
        $computed = $this->solveConstraints($mergedFacts);

        // 6. Validation summary
        $validationSummary = $this->computeValidationSummary($mergedFacts, $computed);

        // On Batch 1, all 5 areas are affected
        $affectedAreas = ['venue', 'schedule', 'food', 'judging', 'budget'];

        // Update affected areas on accepted messages
        foreach ($savedModels as $model) {
            if ($model->status === 'accepted') {
                $model->update(['affected_areas' => $affectedAreas]);
            }
        }

        $mergedFacts['computed'] = $computed;

        $state->update([
            'extracted_facts' => $mergedFacts,
            'validation_summary' => $validationSummary,
        ]);

        return [
            'status' => 'success',
            'affected_areas' => $affectedAreas,
            'computed' => $computed,
            'validation_summary' => $validationSummary,
        ];
    }

    /**
     * Process update batch (Batch > 1).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    protected function processUpdateBatch(PlanState $state, array $messages, int $batchNumber): array
    {
        $existingFacts = $state->extracted_facts ?? [];
        $previousComputed = $existingFacts['computed'] ?? [];

        $firstMsg = $messages[0] ?? [];
        $rawIndex = isset($firstMsg['raw_index']) ? (string) $firstMsg['raw_index'] : null;
        $sender = isset($firstMsg['sender']) ? (string) $firstMsg['sender'] : null;
        $dateStr = isset($firstMsg['date_str']) ? (string) $firstMsg['date_str'] : null;
        $rawContent = (string) ($firstMsg['raw_content'] ?? '');

        // Check if message is an unapproved wish
        if (($firstMsg['is_unapproved_wish'] ?? false) === true || ($firstMsg['intent'] ?? '') === 'unapproved_request') {
            $item = $firstMsg['facts']['fixed_expenses'][0]['item'] ?? 'optional expense';
            $rejectionReason = "Rejected: Unapproved optional expense request ({$item}) outside core hackathon requirements";

            IngestedMessage::create([
                'plan_state_id' => $state->id,
                'batch_number' => $batchNumber,
                'raw_index' => $rawIndex,
                'sender' => $sender,
                'message_date' => $dateStr,
                'raw_content' => $rawContent,
                'status' => 'rejected',
                'rejection_reason' => $rejectionReason,
                'affected_areas' => [],
            ]);

            return [
                'status' => 'rejected',
                'affected_areas' => [],
                'rejection_reason' => $rejectionReason,
            ];
        }

        // Test working facts copy for conflicts
        $workingFacts = $existingFacts;
        $conflictReason = $this->checkForUpdateConflicts($firstMsg, $workingFacts, $previousComputed);

        if ($conflictReason !== null) {
            // Trapped conflicting message! Keep existing plan state untouched
            IngestedMessage::create([
                'plan_state_id' => $state->id,
                'batch_number' => $batchNumber,
                'raw_index' => $rawIndex,
                'sender' => $sender,
                'message_date' => $dateStr,
                'raw_content' => $rawContent,
                'status' => 'rejected',
                'rejection_reason' => $conflictReason,
                'affected_areas' => [],
            ]);

            return [
                'status' => 'rejected',
                'affected_areas' => [],
                'rejection_reason' => $conflictReason,
            ];
        }

        // Merge update into working facts
        $this->applyMessageFacts($firstMsg['facts'] ?? [], $workingFacts);

        $newComputed = $this->solveConstraints($workingFacts);
        $validationSummary = $this->computeValidationSummary($workingFacts, $newComputed);

        // Surgical affected areas detection
        $affectedAreas = $this->detectAffectedAreas($existingFacts, $workingFacts, $previousComputed, $newComputed);

        IngestedMessage::create([
            'plan_state_id' => $state->id,
            'batch_number' => $batchNumber,
            'raw_index' => $rawIndex,
            'sender' => $sender,
            'message_date' => $dateStr,
            'raw_content' => $rawContent,
            'status' => 'accepted',
            'rejection_reason' => null,
            'affected_areas' => $affectedAreas,
        ]);

        $workingFacts['computed'] = $newComputed;

        $state->update([
            'extracted_facts' => $workingFacts,
            'validation_summary' => $validationSummary,
        ]);

        return [
            'status' => 'success',
            'affected_areas' => $affectedAreas,
            'computed' => $newComputed,
            'validation_summary' => $validationSummary,
        ];
    }

    /**
     * Check if an update message violates constraints before applying it.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $previousComputed
     */
    protected function checkForUpdateConflicts(array $message, array $facts, array $previousComputed): ?string
    {
        $students = (int) ($facts['event']['students_count'] ?? 0);
        $staff = (int) ($facts['event']['staff_count'] ?? 0);
        $requiredJudges = (int) ($previousComputed['required_judges'] ?? 0);
        $totalHeadcount = (int) ($previousComputed['total_headcount'] ?? ($students + $staff + $requiredJudges));

        // 1. Food under-ordering check
        $cateringOptions = $message['facts']['catering_options'] ?? [];
        foreach ($cateringOptions as $option) {
            $forcedBoxes = $option['forced_box_count'] ?? null;
            if ($forcedBoxes !== null && is_numeric($forcedBoxes) && (int) $forcedBoxes < $totalHeadcount) {
                $forced = (int) $forcedBoxes;

                return "Conflict: Message attempts to order {$forced} food boxes, which is less than the required total headcount of {$totalHeadcount} people ({$students} students + {$staff} staff + {$requiredJudges} judges).";
            }
        }

        // Check if raw_content contains rounded down text as fallback
        if (preg_match('/rounded\s+(?:your\s+order\s+)?down\s+to\s+(\d+)\s+boxes/i', $message['raw_content'] ?? '', $matches)) {
            $forced = (int) $matches[1];
            if ($forced < $totalHeadcount) {
                return "Conflict: Message attempts to order {$forced} food boxes, which is less than the required total headcount of {$totalHeadcount} people ({$students} students + {$staff} staff + {$requiredJudges} judges).";
            }
        }

        // 2. Venue capacity check
        $venueOptions = $message['facts']['venue_options'] ?? [];
        foreach ($venueOptions as $v) {
            $cap = (int) ($v['capacity'] ?? 0);
            if ($cap > 0 && $cap < $totalHeadcount && ($message['intent'] ?? '') === 'forced_override') {
                $name = $v['name'] ?? 'Selected Venue';

                return "Conflict: Venue {$name} capacity ({$cap}) is less than required total headcount of {$totalHeadcount} people.";
            }
        }

        // 3. Judge shortage check
        $judgingRules = $message['facts']['judging_rules'] ?? null;
        if (is_array($judgingRules) && isset($judgingRules['confirmed_judges'])) {
            $confirmed = (int) $judgingRules['confirmed_judges'];
            if ($confirmed < $requiredJudges && $confirmed > 0) {
                $teamsCount = (int) ($previousComputed['teams_count'] ?? 0);

                return "Conflict: Available confirmed judges ({$confirmed}) is less than required judges ({$requiredJudges}) for {$teamsCount} teams.";
            }
        }

        return null;
    }

    /**
     * Resolve chronological quotes from the same vendor/category and mark superseded ones.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, IngestedMessage>  $savedModels
     */
    protected function resolveChronologicalSuperseding(array $messages, array $savedModels): void
    {
        $categoryGroups = [];

        foreach ($messages as $idx => $msg) {
            if ($savedModels[$idx]->status === 'rejected') {
                continue;
            }

            $cat = $this->determineMessageCategory($msg);
            if ($cat !== null) {
                $categoryGroups[$cat][] = [
                    'index' => $idx,
                    'sort_date' => (int) ($msg['normalized_date_sort'] ?? 0),
                ];
            }
        }

        foreach ($categoryGroups as $cat => $items) {
            if (count($items) <= 1) {
                continue;
            }

            // Sort chronologically ascending
            usort($items, fn ($a, $b) => $a['sort_date'] <=> $b['sort_date']);

            // All items except the latest one are superseded
            $latest = array_pop($items);
            foreach ($items as $supersededItem) {
                $savedModels[$supersededItem['index']]->update([
                    'status' => 'superseded',
                    'rejection_reason' => 'Superseded by newer message from same sender/category',
                ]);
            }
        }
    }

    /**
     * Group category by facts provided or sender.
     *
     * @param  array<string, mixed>  $msg
     */
    protected function determineMessageCategory(array $msg): ?string
    {
        $facts = $msg['facts'] ?? [];
        if (! empty($facts['catering_options'])) {
            return 'catering';
        }
        if (! empty($facts['venue_options'])) {
            return 'venue';
        }
        if (! empty($facts['judging_rules'])) {
            return 'judging';
        }

        $sender = strtolower(trim((string) ($msg['sender'] ?? '')));
        if (str_contains($sender, 'cater')) {
            return 'catering';
        }

        return null;
    }

    /**
     * Merge facts from all accepted messages into a canonical facts array.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, IngestedMessage>  $savedModels
     * @return array<string, mixed>
     */
    protected function mergeAcceptedFacts(array $messages, array $savedModels): array
    {
        $canonical = [
            'event' => [
                'duration_hours' => null,
                'students_count' => null,
                'team_size' => null,
                'teams_count' => null,
                'staff_count' => null,
                'extra_participants_count' => 0,
                'total_budget_limit' => null,
            ],
            'schedule_constraints' => [],
            'venue_options' => [],
            'venue_requirements' => [],
            'catering_options' => [],
            'judging_rules' => [],
            'fixed_expenses' => [],
            'custom_constraints' => [],
        ];

        foreach ($messages as $idx => $msg) {
            if ($savedModels[$idx]->status !== 'accepted') {
                continue;
            }

            $this->applyMessageFacts($msg['facts'] ?? [], $canonical);
        }

        return $canonical;
    }

    /**
     * Apply a single message's facts into the canonical facts array.
     *
     * @param  array<string, mixed>  $sourceFacts
     * @param  array<string, mixed>  $targetFacts
     */
    protected function applyMessageFacts(array $sourceFacts, array &$targetFacts): void
    {
        foreach (['duration_hours', 'students_count', 'team_size', 'teams_count', 'staff_count', 'extra_participants_count', 'total_budget_limit'] as $key) {
            if (isset($sourceFacts[$key]) && $sourceFacts[$key] !== null) {
                $targetFacts['event'][$key] = $sourceFacts[$key];
            }
        }

        if (! empty($sourceFacts['schedule_constraints']) && is_array($sourceFacts['schedule_constraints'])) {
            foreach ($sourceFacts['schedule_constraints'] as $sc) {
                $targetFacts['schedule_constraints'][] = $sc;
            }
        }

        if (! empty($sourceFacts['venue_options']) && is_array($sourceFacts['venue_options'])) {
            $targetFacts['venue_options'] = $sourceFacts['venue_options'];
        }

        if (! empty($sourceFacts['venue_requirements']) && is_array($sourceFacts['venue_requirements'])) {
            $targetFacts['venue_requirements'] = array_unique(array_merge($targetFacts['venue_requirements'], $sourceFacts['venue_requirements']));
        }

        if (! empty($sourceFacts['catering_options']) && is_array($sourceFacts['catering_options'])) {
            $targetFacts['catering_options'] = $sourceFacts['catering_options'];
        }

        if (! empty($sourceFacts['judging_rules']) && is_array($sourceFacts['judging_rules'])) {
            $targetFacts['judging_rules'] = array_merge($targetFacts['judging_rules'], $sourceFacts['judging_rules']);
        }

        if (! empty($sourceFacts['fixed_expenses']) && is_array($sourceFacts['fixed_expenses'])) {
            // Key by item name to allow updates to override previous cost
            $expenseMap = [];
            foreach ($targetFacts['fixed_expenses'] as $exp) {
                $itemName = strtolower(trim((string) ($exp['item'] ?? '')));
                $expenseMap[$itemName] = $exp;
            }

            foreach ($sourceFacts['fixed_expenses'] as $exp) {
                $itemName = strtolower(trim((string) ($exp['item'] ?? '')));
                $expenseMap[$itemName] = $exp;
            }

            $targetFacts['fixed_expenses'] = array_values($expenseMap);
        }

        if (! empty($sourceFacts['custom_constraints']) && is_array($sourceFacts['custom_constraints'])) {
            foreach ($sourceFacts['custom_constraints'] as $cc) {
                $targetFacts['custom_constraints'][] = $cc;
            }
        }
    }

    /**
     * Compute mathematical values, select optimal venue and catering.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    protected function solveConstraints(array $facts): array
    {
        $students = (int) ($facts['event']['students_count'] ?? 0);
        $teamSize = max(1, (int) ($facts['event']['team_size'] ?? 1));
        $teamsCount = ! empty($facts['event']['teams_count'])
            ? (int) $facts['event']['teams_count']
            : (int) ceil($students / $teamSize);

        $maxTeamsPerJudge = max(1, (int) ($facts['judging_rules']['max_teams_per_judge'] ?? 3));
        $requiredJudges = (int) ceil($teamsCount / $maxTeamsPerJudge);
        $confirmedJudges = (int) ($facts['judging_rules']['confirmed_judges'] ?? 0);

        $staff = (int) ($facts['event']['staff_count'] ?? 0);
        $extra = (int) ($facts['event']['extra_participants_count'] ?? 0);
        $totalHeadcount = $students + $staff + $requiredJudges + $extra;

        $honorarium = (float) ($facts['judging_rules']['honorarium_per_judge'] ?? 0);
        $judgingCost = $requiredJudges * $honorarium;

        $fixedExpensesTotal = 0.0;
        foreach ($facts['fixed_expenses'] ?? [] as $exp) {
            if (($exp['is_approved'] ?? true) === true) {
                $fixedExpensesTotal += (float) ($exp['cost'] ?? 0);
            }
        }

        $totalBudgetLimit = (float) ($facts['event']['total_budget_limit'] ?? 0);

        // Optimal Venue Selection: capacity >= totalHeadcount, prefer generator if budget fits
        $validVenues = array_values(array_filter($facts['venue_options'] ?? [], function ($v) use ($totalHeadcount) {
            return (int) ($v['capacity'] ?? 0) >= $totalHeadcount;
        }));

        usort($validVenues, function ($a, $b) {
            $aGen = $this->venueHasGenerator($a);
            $bGen = $this->venueHasGenerator($b);
            if ($aGen !== $bGen) {
                return $bGen <=> $aGen; // Generator preferred
            }

            return (float) ($a['cost'] ?? 0) <=> (float) ($b['cost'] ?? 0);
        });

        // Find best venue that leaves enough budget for food
        $selectedVenue = $validVenues[0] ?? null;

        // Optimal Catering Selection: pick highest quality tier that fits within budget
        $cateringOptions = $facts['catering_options'] ?? [];
        usort($cateringOptions, fn ($a, $b) => (float) ($b['cost_per_head'] ?? 0) <=> (float) ($a['cost_per_head'] ?? 0));

        $selectedCatering = null;
        $venueCost = (float) ($selectedVenue['cost'] ?? 0);

        foreach ($cateringOptions as $opt) {
            $foodCost = ((float) ($opt['cost_per_head'] ?? 0) * $totalHeadcount) + (float) ($opt['delivery_cost'] ?? 0);
            $projectedTotal = $venueCost + $judgingCost + $fixedExpensesTotal + $foodCost;

            if ($projectedTotal <= $totalBudgetLimit || $selectedCatering === null) {
                $selectedCatering = $opt;
                if ($projectedTotal <= $totalBudgetLimit) {
                    break;
                }
            }
        }

        $cateringCost = $selectedCatering !== null
            ? (((float) ($selectedCatering['cost_per_head'] ?? 0) * $totalHeadcount) + (float) ($selectedCatering['delivery_cost'] ?? 0))
            : 0.0;

        $totalCost = $venueCost + $cateringCost + $judgingCost + $fixedExpensesTotal;
        $remainingBudget = $totalBudgetLimit - $totalCost;

        return [
            'teams_count' => $teamsCount,
            'required_judges' => $requiredJudges,
            'confirmed_judges' => $confirmedJudges,
            'total_headcount' => $totalHeadcount,
            'selected_venue' => $selectedVenue,
            'selected_catering' => $selectedCatering,
            'venue_cost' => $venueCost,
            'catering_cost' => $cateringCost,
            'judging_cost' => $judgingCost,
            'fixed_expenses_total' => $fixedExpensesTotal,
            'total_cost' => $totalCost,
            'remaining_budget' => $remainingBudget,
        ];
    }

    /**
     * Check if a venue has a generator or backup power feature.
     *
     * @param  array<string, mixed>  $venue
     */
    protected function venueHasGenerator(array $venue): bool
    {
        $features = $venue['features'] ?? [];
        if (is_array($features)) {
            foreach ($features as $f) {
                if (stripos((string) $f, 'generator') !== false || stripos((string) $f, 'power') !== false) {
                    return true;
                }
            }
        }

        $name = (string) ($venue['name'] ?? '');

        return stripos($name, 'generator') !== false;
    }

    /**
     * Compute validation summary.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $computed
     * @return array<string, bool>
     */
    protected function computeValidationSummary(array $facts, array $computed): array
    {
        $students = (int) ($facts['event']['students_count'] ?? 0);
        $staff = (int) ($facts['event']['staff_count'] ?? 0);
        $extra = (int) ($facts['event']['extra_participants_count'] ?? 0);
        $requiredJudges = (int) ($computed['required_judges'] ?? 0);
        $totalHeadcount = (int) ($computed['total_headcount'] ?? 0);
        $confirmedJudges = (int) ($computed['confirmed_judges'] ?? 0);

        $totalCost = (float) ($computed['total_cost'] ?? 0);
        $totalBudgetLimit = (float) ($facts['event']['total_budget_limit'] ?? 0);

        $venueCapacity = (int) ($computed['selected_venue']['capacity'] ?? 0);
        $durationHours = (float) ($facts['event']['duration_hours'] ?? 4);

        $scheduledMinutes = 0;
        foreach ($facts['schedule_constraints'] ?? [] as $sc) {
            $scheduledMinutes += (int) ($sc['duration_minutes'] ?? 0);
            $scheduledMinutes += (int) ($sc['delay_minutes'] ?? 0);
        }

        return [
            'correct_figures' => $totalHeadcount === ($students + $staff + $requiredJudges + $extra) && $totalHeadcount > 0,
            'within_budget' => $totalCost <= $totalBudgetLimit,
            'venue_fits' => $venueCapacity >= $totalHeadcount && $venueCapacity > 0,
            'schedule_valid' => $scheduledMinutes <= ($durationHours * 60),
            'enough_judges' => $confirmedJudges >= $requiredJudges,
            'all_areas_covered' => ! empty($computed['selected_venue'])
                && ! empty($computed['selected_catering'])
                && ($computed['judging_cost'] ?? 0) > 0
                && $totalCost > 0,
        ];
    }

    /**
     * Surgical affected areas detection between previous and updated facts.
     *
     * @param  array<string, mixed>  $prevFacts
     * @param  array<string, mixed>  $newFacts
     * @param  array<string, mixed>  $prevComputed
     * @param  array<string, mixed>  $newComputed
     * @return array<int, string>
     */
    protected function detectAffectedAreas(
        array $prevFacts,
        array $newFacts,
        array $prevComputed,
        array $newComputed
    ): array {
        $areas = [];

        // Venue area
        $venueChanged = ($prevComputed['selected_venue']['name'] ?? '') !== ($newComputed['selected_venue']['name'] ?? '')
            || ($prevComputed['total_headcount'] ?? 0) !== ($newComputed['total_headcount'] ?? 0)
            || ($prevFacts['venue_options'] ?? []) != ($newFacts['venue_options'] ?? []);
        if ($venueChanged) {
            $areas[] = 'venue';
        }

        // Schedule area
        $scheduleChanged = ($prevFacts['event']['duration_hours'] ?? 0) != ($newFacts['event']['duration_hours'] ?? 0)
            || ($prevFacts['schedule_constraints'] ?? []) != ($newFacts['schedule_constraints'] ?? []);
        if ($scheduleChanged) {
            $areas[] = 'schedule';
        }

        // Food area
        $foodChanged = ($prevComputed['selected_catering']['name'] ?? '') !== ($newComputed['selected_catering']['name'] ?? '')
            || ($prevComputed['selected_catering']['cost_per_head'] ?? 0) != ($newComputed['selected_catering']['cost_per_head'] ?? 0)
            || ($prevComputed['total_headcount'] ?? 0) !== ($newComputed['total_headcount'] ?? 0);
        if ($foodChanged) {
            $areas[] = 'food';
        }

        // Judging area
        $judgingChanged = ($prevComputed['required_judges'] ?? 0) !== ($newComputed['required_judges'] ?? 0)
            || ($prevFacts['judging_rules'] ?? []) != ($newFacts['judging_rules'] ?? [])
            || ($prevComputed['teams_count'] ?? 0) !== ($newComputed['teams_count'] ?? 0);
        if ($judgingChanged) {
            $areas[] = 'judging';
        }

        // Budget area
        $budgetChanged = ($prevComputed['total_cost'] ?? 0) != ($newComputed['total_cost'] ?? 0)
            || ($prevComputed['remaining_budget'] ?? 0) != ($newComputed['remaining_budget'] ?? 0)
            || ($prevFacts['fixed_expenses'] ?? []) != ($newFacts['fixed_expenses'] ?? [])
            || ($prevFacts['event']['total_budget_limit'] ?? 0) != ($newFacts['event']['total_budget_limit'] ?? 0);
        if ($budgetChanged) {
            $areas[] = 'budget';
        }

        return $areas;
    }
}
