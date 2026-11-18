<?php

namespace App\Services;

use App\Enums\PlanArea;
use App\Models\PlanState;

class AreaPlannerService
{
    public function __construct(
        protected LlmClientService $llmClient
    ) {}

    /**
     * Generate or update only the affected areas for the given plan state.
     *
     * @param  array<int, string>  $affectedAreas
     */
    public function generateAffectedAreas(PlanState $state, array $affectedAreas, int $batchNumber): PlanState
    {
        $canonicalOrder = ['venue', 'schedule', 'food', 'judging', 'budget'];
        $facts = $state->extracted_facts ?? [];
        $computed = $facts['computed'] ?? [];

        foreach ($canonicalOrder as $areaName) {
            if (! in_array($areaName, $affectedAreas, true)) {
                continue;
            }

            match ($areaName) {
                'venue' => $this->planVenue($state, $facts, $computed, $batchNumber),
                'schedule' => $this->planSchedule($state, $facts, $computed, $batchNumber),
                'food' => $this->planFood($state, $facts, $computed, $batchNumber),
                'judging' => $this->planJudging($state, $facts, $computed, $batchNumber),
                'budget' => $this->planBudget($state, $facts, $computed, $batchNumber),
            };
        }

        $state->refresh();

        $allCovered = ! empty($state->venue_plan)
            && ! empty($state->schedule_plan)
            && ! empty($state->food_plan)
            && ! empty($state->judging_plan)
            && ! empty($state->budget_plan);

        if ($allCovered) {
            $summary = $state->validation_summary ?? [];
            $summary['all_areas_covered'] = true;
            $state->validation_summary = $summary;
            $state->save();
        }

        return $state;
    }

    protected function planVenue(PlanState $state, array $facts, array $computed, int $batchNumber): void
    {
        $selectedVenue = $computed['selected_venue'] ?? [];
        $totalHeadcount = (int) ($computed['total_headcount'] ?? 0);
        $venueOptions = $facts['venue_options'] ?? [];

        $systemPrompt = 'You are an expert venue planner. Output ONLY valid JSON matching: {"selected_venue": string, "capacity": int, "required_headcount": int, "cost": number, "features": array, "justification": string}.';
        $userPrompt = 'Selected venue: '.json_encode($selectedVenue)."\nRequired headcount: {$totalHeadcount}\nAll options: ".json_encode($venueOptions);

        $result = $this->llmClient->call(
            state: $state,
            batchNumber: $batchNumber,
            area: PlanArea::Venue,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            temperature: 0.0
        );

        $payload = $result['data'] ?? (is_array($result['content']) ? $result['content'] : ['raw' => $result['raw_content']]);
        $state->update(['venue_plan' => $payload]);
    }

    protected function planSchedule(PlanState $state, array $facts, array $computed, int $batchNumber): void
    {
        $durationHours = (float) ($facts['event']['duration_hours'] ?? 4);
        $totalDurationMinutes = (int) ($durationHours * 60);
        $constraints = $facts['schedule_constraints'] ?? [];
        $teamsCount = (int) ($computed['teams_count'] ?? 0);
        $minutesPerTeam = (int) ($facts['judging_rules']['minutes_per_team'] ?? 15);

        $systemPrompt = 'You are an expert hackathon schedule planner. Output ONLY valid JSON matching: {"total_duration_minutes": int, "timeline": [{"time_slot": string, "activity": string, "duration_minutes": int, "notes": string}], "constraints_honored": array}. Ensure sum of duration_minutes in timeline equals '.$totalDurationMinutes.'.';
        $userPrompt = "Total duration: {$totalDurationMinutes} mins ({$durationHours} hours). Teams: {$teamsCount}. Judging per team: {$minutesPerTeam} mins.\nSchedule constraints: ".json_encode($constraints);

        $result = $this->llmClient->call(
            state: $state,
            batchNumber: $batchNumber,
            area: PlanArea::Schedule,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            temperature: 0.0
        );

        $payload = $result['data'] ?? (is_array($result['content']) ? $result['content'] : ['raw' => $result['raw_content']]);
        $state->update(['schedule_plan' => $payload]);
    }

    protected function planFood(PlanState $state, array $facts, array $computed, int $batchNumber): void
    {
        $selectedCatering = $computed['selected_catering'] ?? [];
        $students = (int) ($facts['event']['students_count'] ?? 0);
        $staff = (int) ($facts['event']['staff_count'] ?? 0);
        $judges = (int) ($computed['required_judges'] ?? 0);
        $total = (int) ($computed['total_headcount'] ?? ($students + $staff + $judges));
        $cateringCost = (float) ($computed['catering_cost'] ?? 0);

        $systemPrompt = 'You are an expert event catering planner. Output ONLY valid JSON matching: {"selected_package": string, "headcount_breakdown": {"students": int, "staff": int, "judges": int, "total": int}, "unit_cost": number, "delivery_cost": number, "total_food_cost": number, "notes": string}.';
        $userPrompt = 'Selected catering: '.json_encode($selectedCatering)."\nHeadcount: students={$students}, staff={$staff}, judges={$judges}, total={$total}\nTotal catering cost: Rs {$cateringCost}";

        $result = $this->llmClient->call(
            state: $state,
            batchNumber: $batchNumber,
            area: PlanArea::Food,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            temperature: 0.0
        );

        $payload = $result['data'] ?? (is_array($result['content']) ? $result['content'] : ['raw' => $result['raw_content']]);
        $state->update(['food_plan' => $payload]);
    }

    protected function planJudging(PlanState $state, array $facts, array $computed, int $batchNumber): void
    {
        $totalTeams = (int) ($computed['teams_count'] ?? 0);
        $confirmedJudges = (int) ($computed['confirmed_judges'] ?? 0);
        $assignedJudges = (int) ($computed['required_judges'] ?? 0);
        $standbyJudges = max(0, $confirmedJudges - $assignedJudges);
        $maxTeams = (int) ($facts['judging_rules']['max_teams_per_judge'] ?? 3);
        $minutesPerTeam = (int) ($facts['judging_rules']['minutes_per_team'] ?? 15);
        $honorarium = (float) ($facts['judging_rules']['honorarium_per_judge'] ?? 0);
        $judgingCost = (float) ($computed['judging_cost'] ?? 0);

        $constraints = $facts['schedule_constraints'] ?? [];

        $systemPrompt = 'You are an expert judging coordinator. Output ONLY valid JSON matching: {"total_teams": int, "confirmed_judges": int, "assigned_judges": int, "standby_judges": int, "max_teams_per_judge": int, "minutes_per_team": int, "honorarium_per_judge": number, "total_judging_cost": number, "contingency_notes": string}.';
        $userPrompt = "Total teams: {$totalTeams}\nConfirmed judges: {$confirmedJudges}\nAssigned active judges: {$assignedJudges}\nStandby judges: {$standbyJudges}\nMax teams per judge: {$maxTeams}\nMinutes per team: {$minutesPerTeam}\nHonorarium: Rs {$honorarium} each\nTotal judging cost: Rs {$judgingCost}\nNotes/delays: ".json_encode($constraints);

        $result = $this->llmClient->call(
            state: $state,
            batchNumber: $batchNumber,
            area: PlanArea::Judging,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            temperature: 0.0
        );

        $payload = $result['data'] ?? (is_array($result['content']) ? $result['content'] : ['raw' => $result['raw_content']]);
        $state->update(['judging_plan' => $payload]);
    }

    protected function planBudget(PlanState $state, array $facts, array $computed, int $batchNumber): void
    {
        $budgetLimit = (float) ($facts['event']['total_budget_limit'] ?? 0);
        $venueCost = (float) ($computed['venue_cost'] ?? 0);
        $foodCost = (float) ($computed['catering_cost'] ?? 0);
        $judgingCost = (float) ($computed['judging_cost'] ?? 0);
        $fixedExpenses = $facts['fixed_expenses'] ?? [];
        $totalSpent = (float) ($computed['total_cost'] ?? 0);
        $remainingBalance = (float) ($computed['remaining_budget'] ?? 0);
        $withinBudget = $totalSpent <= $budgetLimit;

        $rejectedMessages = $state->ingestedMessages()->where('status', 'rejected')->get();
        $excludedItems = [];
        foreach ($rejectedMessages as $msg) {
            $excludedItems[] = [
                'message_index' => $msg->raw_index,
                'sender' => $msg->sender,
                'reason' => $msg->rejection_reason,
            ];
        }

        $systemPrompt = 'You are an expert financial budget planner. Output ONLY valid JSON matching: {"budget_limit": number, "line_items": [{"category": string, "description": string, "amount": number}], "total_spent": number, "remaining_balance": number, "within_budget": bool, "excluded_items": array}.';
        $userPrompt = "Budget limit: Rs {$budgetLimit}\nVenue cost: Rs {$venueCost}\nFood cost: Rs {$foodCost}\nJudging cost: Rs {$judgingCost}\nFixed expenses: ".json_encode($fixedExpenses)."\nTotal spent: Rs {$totalSpent}\nRemaining balance: Rs {$remainingBalance}\nWithin budget: ".($withinBudget ? 'true' : 'false')."\nExcluded/rejected items: ".json_encode($excludedItems);

        $result = $this->llmClient->call(
            state: $state,
            batchNumber: $batchNumber,
            area: PlanArea::Budget,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            temperature: 0.0
        );

        $payload = $result['data'] ?? (is_array($result['content']) ? $result['content'] : ['raw' => $result['raw_content']]);
        $state->update(['budget_plan' => $payload]);
    }
}
