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

        // 2. Identify unapproved wishes vs operational timing notices
        foreach ($messages as $idx => $msg) {
            $rawContent = (string) ($msg['raw_content'] ?? '');

            // Operational timing notices (late arrivals, traffic delays) are valid operational constraints, NEVER unapproved wishes
            $isDelayNotice = preg_match('/\b(?:(\d+)\s*(?:minute|min|m)s?\s*late|traffic|delayed?)\b/i', $rawContent, $delayMatches);
            if ($isDelayNotice) {
                $delayMinutes = isset($delayMatches[1]) && is_numeric($delayMatches[1]) ? (int) $delayMatches[1] : 10;
                if (empty($messages[$idx]['facts']['schedule_constraints'])) {
                    $messages[$idx]['facts']['schedule_constraints'] = [
                        [
                            'type' => 'delay',
                            'description' => 'Judge late arrival buffer (traffic)',
                            'delay_minutes' => $delayMinutes,
                            'duration_minutes' => $delayMinutes,
                        ],
                    ];
                }
                $savedModels[$idx]->update([
                    'status' => 'accepted',
                    'rejection_reason' => null,
                ]);

                continue;
            }

            $isUnapproved = ($msg['is_unapproved_wish'] ?? false) === true
                || ($msg['intent'] ?? '') === 'unapproved_request'
                || preg_match('/\b(dj|band|concert|afterparty|gaming\s+lounge)\b/i', $rawContent);

            if ($isUnapproved) {
                $item = $msg['facts']['fixed_expenses'][0]['item']
                    ?? (preg_match('/\b(dj|band|concert|party|afterparty)\b/i', $rawContent, $matches) ? strtoupper($matches[1]) : 'optional expense');

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

        // Delay / late arrival notices are operational constraints, NOT unapproved wishes
        $isDelayNotice = preg_match('/\b(?:(\d+)\s*(?:minute|min|m)s?\s*late|traffic|delayed?)\b/i', $rawContent);

        // Check if message is an unapproved wish
        if (! $isDelayNotice && (($firstMsg['is_unapproved_wish'] ?? false) === true || ($firstMsg['intent'] ?? '') === 'unapproved_request')) {
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
        $this->applyMessageFacts($firstMsg['facts'] ?? [], $workingFacts, $rawContent);

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

        // 4. Custom constraints evaluation on hypothetical state
        $hypotheticalFacts = $facts;
        $this->applyMessageFacts($message['facts'] ?? [], $hypotheticalFacts, $message['raw_content'] ?? null);
        $hypotheticalComputed = $this->solveConstraints($hypotheticalFacts);

        $customConflict = $this->evaluateCustomConstraints($message, $hypotheticalFacts, $previousComputed, $hypotheticalComputed);
        if ($customConflict !== null) {
            return $customConflict;
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

            $this->applyMessageFacts($msg['facts'] ?? [], $canonical, $msg['raw_content'] ?? null);
        }

        return $canonical;
    }

    /**
     * Apply a single message's facts into the canonical facts array.
     *
     * @param  array<string, mixed>  $sourceFacts
     * @param  array<string, mixed>  $targetFacts
     */
    protected function applyMessageFacts(array $sourceFacts, array &$targetFacts, ?string $rawContent = null): void
    {
        foreach (['duration_hours', 'students_count', 'team_size', 'teams_count', 'staff_count', 'extra_participants_count', 'total_budget_limit'] as $key) {
            if (isset($sourceFacts[$key]) && $sourceFacts[$key] !== null) {
                $targetFacts['event'][$key] = $sourceFacts[$key];
            }
        }

        // Dynamic budget limit fallback if LLM omitted total_budget_limit key
        if (empty($targetFacts['event']['total_budget_limit']) && ! empty($rawContent)) {
            if (preg_match('/\bbudget\s+(?:is|cap|limit)?\s*(?:Rs\.?|PKR)?\s*([\d,]+)/i', $rawContent, $m)) {
                $parsedBudget = (float) str_replace(',', '', $m[1]);
                if ($parsedBudget > 0) {
                    $targetFacts['event']['total_budget_limit'] = $parsedBudget;
                }
            }
        }

        if (! empty($sourceFacts['schedule_constraints']) && is_array($sourceFacts['schedule_constraints'])) {
            foreach ($sourceFacts['schedule_constraints'] as $sc) {
                $targetFacts['schedule_constraints'][] = $sc;
            }
        }

        // Delay / late arrival notices fallback
        if (preg_match('/\b(?:(\d+)\s*(?:minute|min|m)s?\s*late|traffic|delayed?)\b/i', $rawContent ?? '', $dm)) {
            $delayMins = isset($dm[1]) && is_numeric($dm[1]) ? (int) $dm[1] : 10;
            $alreadyHas = false;
            foreach ($targetFacts['schedule_constraints'] as $sc) {
                if (($sc['type'] ?? '') === 'delay' || str_contains(strtolower($sc['description'] ?? ''), 'late')) {
                    $alreadyHas = true;
                    break;
                }
            }
            if (! $alreadyHas) {
                $targetFacts['schedule_constraints'][] = [
                    'type' => 'delay',
                    'description' => "Judge late arrival buffer ({$delayMins} mins)",
                    'delay_minutes' => $delayMins,
                    'duration_minutes' => $delayMins,
                ];
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
            $existingConstraints = [];
            foreach ($targetFacts['custom_constraints'] ?? [] as $cc) {
                if (is_array($cc)) {
                    $key = strtolower(trim(($cc['area'] ?? '').':'.($cc['rule_type'] ?? '').':'.($cc['target'] ?? '')));
                    $existingConstraints[$key] = $cc;
                }
            }

            foreach ($sourceFacts['custom_constraints'] as $cc) {
                if (is_array($cc)) {
                    $key = strtolower(trim(($cc['area'] ?? '').':'.($cc['rule_type'] ?? '').':'.($cc['target'] ?? '')));
                    $existingConstraints[$key] = [
                        'area' => strtolower(trim((string) ($cc['area'] ?? ''))),
                        'rule_type' => strtolower(trim((string) ($cc['rule_type'] ?? ''))),
                        'target' => strtolower(trim((string) ($cc['target'] ?? ''))),
                        'value' => $cc['value'] ?? null,
                        'description' => (string) ($cc['description'] ?? ''),
                    ];
                }
            }

            $targetFacts['custom_constraints'] = array_values($existingConstraints);
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

        // Optimal Venue Selection: capacity >= totalHeadcount and fulfilling feature requirements
        $requiredFeatures = [];
        foreach ($facts['venue_requirements'] ?? [] as $req) {
            if (is_string($req) && trim($req) !== '') {
                $requiredFeatures[] = strtolower(trim($req));
            }
        }
        foreach ($facts['custom_constraints'] ?? [] as $cc) {
            if (is_array($cc) && ($cc['area'] ?? '') === 'venue' && ($cc['rule_type'] ?? '') === 'required') {
                $feat = trim((string) ($cc['target'] ?? '')) ?: trim((string) ($cc['value'] ?? ''));
                if ($feat !== '') {
                    $requiredFeatures[] = strtolower($feat);
                }
            }
        }
        $requiredFeatures = array_values(array_unique($requiredFeatures));

        $forbiddenFeatures = [];
        foreach ($facts['custom_constraints'] ?? [] as $cc) {
            if (is_array($cc) && ($cc['area'] ?? '') === 'venue' && ($cc['rule_type'] ?? '') === 'forbidden') {
                $feat = trim((string) ($cc['target'] ?? '')) ?: trim((string) ($cc['value'] ?? ''));
                if ($feat !== '') {
                    $forbiddenFeatures[] = strtolower($feat);
                }
            }
        }
        $forbiddenFeatures = array_values(array_unique($forbiddenFeatures));

        $validVenues = array_values(array_filter($facts['venue_options'] ?? [], function ($v) use ($totalHeadcount, $requiredFeatures, $forbiddenFeatures) {
            if ((int) ($v['capacity'] ?? 0) < $totalHeadcount) {
                return false;
            }
            foreach ($requiredFeatures as $req) {
                if (! $this->venueHasFeature($v, $req)) {
                    return false;
                }
            }
            foreach ($forbiddenFeatures as $forb) {
                if ($this->venueHasFeature($v, $forb)) {
                    return false;
                }
            }

            return true;
        }));

        if (empty($validVenues) && empty($requiredFeatures) && empty($forbiddenFeatures)) {
            $validVenues = array_values(array_filter($facts['venue_options'] ?? [], function ($v) use ($totalHeadcount) {
                return (int) ($v['capacity'] ?? 0) >= $totalHeadcount;
            }));
        }

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
    public function venueHasGenerator(array $venue): bool
    {
        return $this->venueHasFeature($venue, 'generator');
    }

    /**
     * Check if a venue satisfies a specific named feature or requirement.
     *
     * @param  array<string, mixed>  $venue
     */
    public function venueHasFeature(array $venue, string $feature): bool
    {
        $feature = strtolower(trim($feature));
        if ($feature === '') {
            return false;
        }

        $featureTokens = array_filter(preg_split('/[\s_\-]+/', $feature));

        // Check venue features list
        $features = $venue['features'] ?? [];
        if (is_array($features)) {
            foreach ($features as $f) {
                $fLower = strtolower(trim((string) $f));
                if (str_contains($fLower, $feature)) {
                    return true;
                }
                foreach ($featureTokens as $token) {
                    if (strlen($token) >= 4 && str_contains($fLower, $token)) {
                        return true;
                    }
                }
            }
        }

        // Check venue name
        $nameLower = strtolower(trim((string) ($venue['name'] ?? '')));
        if (str_contains($nameLower, $feature)) {
            return true;
        }
        foreach ($featureTokens as $token) {
            if (strlen($token) >= 4 && str_contains($nameLower, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate active and incoming custom constraints against the proposed plan state.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $hypotheticalFacts
     * @param  array<string, mixed>  $previousComputed
     * @param  array<string, mixed>  $hypotheticalComputed
     * @return string|null Rejection reason if conflict detected, null if valid
     */
    public function evaluateCustomConstraints(
        array $message,
        array $hypotheticalFacts,
        array $previousComputed,
        array $hypotheticalComputed
    ): ?string {
        $customConstraints = $hypotheticalFacts['custom_constraints'] ?? [];
        if (! is_array($customConstraints) || empty($customConstraints)) {
            return null;
        }

        $totalHeadcount = (int) ($hypotheticalComputed['total_headcount'] ?? 0);

        foreach ($customConstraints as $cc) {
            if (! is_array($cc)) {
                continue;
            }

            $area = strtolower(trim((string) ($cc['area'] ?? '')));
            $ruleType = strtolower(trim((string) ($cc['rule_type'] ?? '')));
            $target = strtolower(trim((string) ($cc['target'] ?? '')));
            $value = $cc['value'] ?? null;
            $desc = trim((string) ($cc['description'] ?? ''));

            // 1. VENUE CONSTRAINTS
            if ($area === 'venue') {
                if ($ruleType === 'required') {
                    $feature = $target !== '' ? $target : strtolower(trim((string) $value));
                    $selectedVenue = $hypotheticalComputed['selected_venue'] ?? null;

                    if ($selectedVenue === null || ! $this->venueHasFeature($selectedVenue, $feature)) {
                        $reasonDesc = $desc ?: "Mandatory requirement for {$feature}";

                        return "Conflict: Required venue feature '{$feature}' cannot be satisfied by any available venue with sufficient capacity ({$totalHeadcount} attendees) ({$reasonDesc}).";
                    }
                } elseif ($ruleType === 'forbidden') {
                    $feature = $target !== '' ? $target : strtolower(trim((string) $value));
                    $selectedVenue = $hypotheticalComputed['selected_venue'] ?? null;

                    if ($selectedVenue !== null && $this->venueHasFeature($selectedVenue, $feature)) {
                        $venueName = $selectedVenue['name'] ?? 'Selected venue';
                        $reasonDesc = $desc ?: "Forbidden attribute: {$feature}";

                        return "Conflict: Venue '{$venueName}' contains forbidden attribute '{$feature}' ({$reasonDesc}).";
                    }
                } elseif ($ruleType === 'min' && ($target === 'capacity' || $target === 'seats')) {
                    $minCap = (int) $value;
                    $selectedVenue = $hypotheticalComputed['selected_venue'] ?? null;
                    $cap = (int) ($selectedVenue['capacity'] ?? 0);
                    if ($selectedVenue !== null && $cap < $minCap) {
                        return "Conflict: Venue capacity ({$cap}) is less than required minimum capacity of {$minCap} ({$desc}).";
                    }
                }
            }

            // 2. FOOD CONSTRAINTS
            if ($area === 'food') {
                if ($ruleType === 'min') {
                    $minBoxes = (int) $value;
                    $forcedBoxes = null;
                    foreach ($message['facts']['catering_options'] ?? [] as $opt) {
                        if (isset($opt['forced_box_count']) && $opt['forced_box_count'] !== null) {
                            $forcedBoxes = (int) $opt['forced_box_count'];
                        }
                    }

                    if ($forcedBoxes !== null && $forcedBoxes < $minBoxes) {
                        $reasonDesc = $desc ?: "Minimum {$minBoxes} required";

                        return "Conflict: Ordered food boxes ({$forcedBoxes}) is less than required minimum of {$minBoxes} ({$reasonDesc}).";
                    }

                    if (str_contains($target, 'veg') || str_contains($target, 'diet') || str_contains($target, 'halal')) {
                        if ($forcedBoxes !== null && $forcedBoxes < $minBoxes) {
                            return "Conflict: Food order does not satisfy dietary requirement of {$minBoxes} {$target} portions ({$desc}).";
                        }
                    }
                } elseif ($ruleType === 'max') {
                    if (str_contains($target, 'cost_per_head') || str_contains($target, 'price') || str_contains($target, 'head')) {
                        $maxCph = (float) $value;
                        $selectedCatering = $hypotheticalComputed['selected_catering'] ?? null;
                        $cph = (float) ($selectedCatering['cost_per_head'] ?? 0);
                        if ($cph > $maxCph) {
                            return 'Conflict: Catering cost per head (Rs '.number_format($cph).') exceeds maximum allowed cap of Rs '.number_format($maxCph)." ({$desc}).";
                        }
                    } elseif (str_contains($target, 'food_cost') || str_contains($target, 'catering_cost')) {
                        $maxFoodCost = (float) $value;
                        $cateringCost = (float) ($hypotheticalComputed['catering_cost'] ?? 0);
                        if ($cateringCost > $maxFoodCost) {
                            return 'Conflict: Catering total cost (Rs '.number_format($cateringCost).') exceeds maximum catering cap of Rs '.number_format($maxFoodCost)." ({$desc}).";
                        }
                    }
                }
            }

            // 3. BUDGET CONSTRAINTS
            if ($area === 'budget') {
                if ($ruleType === 'max') {
                    $maxBudget = (float) $value;

                    // Per-category fixed expense caps
                    foreach ($hypotheticalFacts['fixed_expenses'] ?? [] as $exp) {
                        $expItem = strtolower(trim((string) ($exp['item'] ?? '')));
                        $targetClean = strtolower(trim((string) $target));

                        if ($targetClean !== '' && (str_contains($expItem, $targetClean) || str_contains($targetClean, $expItem))) {
                            $cost = (float) ($exp['cost'] ?? 0);
                            if ($cost > $maxBudget) {
                                return "Conflict: Expense for '{$exp['item']}' (Rs ".number_format($cost).') exceeds maximum category budget cap of Rs '.number_format($maxBudget)." ({$desc}).";
                            }
                        }
                    }

                    // Total event expenditure cap
                    if (in_array($target, ['total_budget', 'total_cost', 'total_spend', 'spend', 'overall'], true)) {
                        $totalCost = (float) ($hypotheticalComputed['total_cost'] ?? 0);
                        if ($totalCost > $maxBudget) {
                            return 'Conflict: Projected total event spend (Rs '.number_format($totalCost).') exceeds maximum budget limit of Rs '.number_format($maxBudget)." ({$desc}).";
                        }
                    }

                    // Venue expenditure cap
                    if (in_array($target, ['venue_cost', 'venue_budget', 'venue'], true)) {
                        $venueCost = (float) ($hypotheticalComputed['venue_cost'] ?? 0);
                        if ($venueCost > $maxBudget) {
                            return 'Conflict: Venue cost (Rs '.number_format($venueCost).') exceeds maximum venue budget cap of Rs '.number_format($maxBudget)." ({$desc}).";
                        }
                    }
                } elseif ($ruleType === 'min') {
                    if (in_array($target, ['remaining_budget', 'reserve', 'contingency', 'surplus'], true)) {
                        $minReserve = (float) $value;
                        $remaining = (float) ($hypotheticalComputed['remaining_budget'] ?? 0);
                        if ($remaining < $minReserve) {
                            return 'Conflict: Remaining budget (Rs '.number_format($remaining).') falls below required minimum reserve of Rs '.number_format($minReserve)." ({$desc}).";
                        }
                    }
                }
            }

            // 4. JUDGING CONSTRAINTS
            if ($area === 'judging') {
                if ($ruleType === 'max') {
                    $maxWorkload = (float) $value;
                    $teamsCount = (int) ($hypotheticalComputed['teams_count'] ?? 0);
                    $confirmedJudges = (int) ($hypotheticalComputed['confirmed_judges'] ?? 0);

                    if (in_array($target, ['teams_per_judge', 'max_teams_per_judge', 'workload', 'teams', 'shift_limit'], true)) {
                        $effectiveWorkload = $confirmedJudges > 0 ? ceil($teamsCount / $confirmedJudges) : $teamsCount;
                        if ($effectiveWorkload > $maxWorkload) {
                            return "Conflict: Judge workload ({$effectiveWorkload} teams/judge) exceeds maximum permitted limit of {$maxWorkload} ({$desc}).";
                        }
                    }
                } elseif ($ruleType === 'min') {
                    if (in_array($target, ['confirmed_judges', 'judges_count', 'judges'], true)) {
                        $minJudges = (int) $value;
                        $confirmedJudges = (int) ($hypotheticalComputed['confirmed_judges'] ?? 0);
                        if ($confirmedJudges > 0 && $confirmedJudges < $minJudges) {
                            return "Conflict: Confirmed judges ({$confirmedJudges}) is below required minimum of {$minJudges} ({$desc}).";
                        }
                    }
                }
            }

            // 5. SCHEDULE CONSTRAINTS
            if ($area === 'schedule') {
                if ($ruleType === 'min') {
                    if (in_array($target, ['break_duration', 'break_interval', 'prayer_break', 'lunch_break', 'gap', 'break'], true)) {
                        $minBreak = (int) $value;
                        foreach ($hypotheticalFacts['schedule_constraints'] ?? [] as $sc) {
                            $type = strtolower((string) ($sc['type'] ?? ''));
                            $scDesc = strtolower((string) ($sc['description'] ?? ''));
                            if (str_contains($type, 'break') || str_contains($scDesc, 'break') || str_contains($scDesc, 'prayer')) {
                                $duration = (int) ($sc['duration_minutes'] ?? 0);
                                if ($duration > 0 && $duration < $minBreak) {
                                    return "Conflict: Break duration ({$duration} mins) is less than required minimum of {$minBreak} mins ({$desc}).";
                                }
                            }
                        }
                    }
                } elseif ($ruleType === 'max') {
                    if (in_array($target, ['duration_hours', 'event_duration', 'total_duration'], true)) {
                        $maxHours = (float) $value;
                        $actualHours = (float) ($hypotheticalFacts['event']['duration_hours'] ?? 0);
                        if ($actualHours > $maxHours) {
                            return "Conflict: Event duration ({$actualHours} hrs) exceeds maximum permitted limit of {$maxHours} hrs ({$desc}).";
                        }
                    }
                }
            }
        }

        // Check if overall budget limit is exceeded
        $totalCost = (float) ($hypotheticalComputed['total_cost'] ?? 0);
        $totalBudgetLimit = (float) ($hypotheticalFacts['event']['total_budget_limit'] ?? 0);
        if ($totalBudgetLimit > 0 && $totalCost > $totalBudgetLimit) {
            return 'Conflict: Total event cost (Rs '.number_format($totalCost).') exceeds total budget limit of Rs '.number_format($totalBudgetLimit).'.';
        }

        return null;
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
            $scheduledMinutes += max((int) ($sc['duration_minutes'] ?? 0), (int) ($sc['delay_minutes'] ?? 0));
        }

        // Check venue requirements
        $venueRequirementsMet = true;
        if (! empty($computed['selected_venue'])) {
            $requiredFeatures = [];
            foreach ($facts['venue_requirements'] ?? [] as $req) {
                if (is_string($req) && trim($req) !== '') {
                    $requiredFeatures[] = strtolower(trim($req));
                }
            }
            foreach ($facts['custom_constraints'] ?? [] as $cc) {
                if (is_array($cc) && ($cc['area'] ?? '') === 'venue' && ($cc['rule_type'] ?? '') === 'required') {
                    $feat = trim((string) ($cc['target'] ?? '')) ?: trim((string) ($cc['value'] ?? ''));
                    if ($feat !== '') {
                        $requiredFeatures[] = strtolower($feat);
                    }
                }
            }
            foreach ($requiredFeatures as $req) {
                if (! $this->venueHasFeature($computed['selected_venue'], $req)) {
                    $venueRequirementsMet = false;
                    break;
                }
            }
        } else {
            $venueRequirementsMet = false;
        }

        return [
            'correct_figures' => $totalHeadcount === ($students + $staff + $requiredJudges + $extra) && $totalHeadcount > 0,
            'within_budget' => $totalCost <= $totalBudgetLimit,
            'venue_fits' => $venueCapacity >= $totalHeadcount && $venueCapacity > 0 && $venueRequirementsMet,
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

        // Custom constraints area detection
        if (($prevFacts['custom_constraints'] ?? []) != ($newFacts['custom_constraints'] ?? [])) {
            $prevKeys = array_map(fn ($c) => ($c['area'] ?? '').':'.($c['rule_type'] ?? '').':'.($c['target'] ?? ''), $prevFacts['custom_constraints'] ?? []);
            foreach ($newFacts['custom_constraints'] ?? [] as $newCc) {
                $key = ($newCc['area'] ?? '').':'.($newCc['rule_type'] ?? '').':'.($newCc['target'] ?? '');
                if (! in_array($key, $prevKeys, true)) {
                    $area = strtolower(trim((string) ($newCc['area'] ?? '')));
                    if (in_array($area, ['venue', 'schedule', 'food', 'judging', 'budget'], true) && ! in_array($area, $areas, true)) {
                        $areas[] = $area;
                    }
                }
            }
        }

        return $areas;
    }
}
