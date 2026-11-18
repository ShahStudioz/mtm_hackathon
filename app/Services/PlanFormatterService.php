<?php

namespace App\Services;

use App\Models\PlanState;

class PlanFormatterService
{
    public function __construct(
        protected TraceFormatterService $traceFormatter
    ) {}

    /**
     * Format a clean, human- and judge-readable event plan text report.
     */
    public function formatPlan(PlanState $state): string
    {
        $lines = [];

        $lines[] = '================================================================================';
        $lines[] = '                              HACKATHON EVENT PLAN                              ';
        $lines[] = '================================================================================';
        $lines[] = "Plan State ID: {$state->id} | Status: {$state->status}";
        $lines[] = '';

        // 1. Venue Plan
        $venue = $state->venue_plan ?? [];
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = '1. VENUE PLAN';
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = 'Selected Venue: '.($venue['selected_venue'] ?? 'None selected');
        $lines[] = 'Capacity: '.($venue['capacity'] ?? 'N/A').' | Required Headcount: '.($venue['required_headcount'] ?? 'N/A');
        $lines[] = 'Cost: Rs '.number_format((float) ($venue['cost'] ?? 0));
        $features = isset($venue['features']) && is_array($venue['features']) ? implode(', ', $venue['features']) : 'Standard';
        $lines[] = "Features: {$features}";
        if (! empty($venue['justification'])) {
            $lines[] = "Justification: {$venue['justification']}";
        }
        $lines[] = '';

        // 2. Schedule Plan
        $schedule = $state->schedule_plan ?? [];
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = '2. SCHEDULE PLAN';
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = 'Total Duration: '.($schedule['total_duration_minutes'] ?? 'N/A').' minutes';
        if (! empty($schedule['timeline']) && is_array($schedule['timeline'])) {
            $lines[] = 'Timeline:';
            foreach ($schedule['timeline'] as $slot) {
                $time = $slot['time_slot'] ?? '';
                $act = $slot['activity'] ?? '';
                $dur = isset($slot['duration_minutes']) ? " ({$slot['duration_minutes']} mins)" : '';
                $notes = ! empty($slot['notes']) ? " - Notes: {$slot['notes']}" : '';
                $lines[] = "  * {$time}: {$act}{$dur}{$notes}";
            }
        }
        if (! empty($schedule['constraints_honored']) && is_array($schedule['constraints_honored'])) {
            $lines[] = 'Constraints Honored: '.implode(', ', $schedule['constraints_honored']);
        }
        $lines[] = '';

        // 3. Food & Catering Plan
        $food = $state->food_plan ?? [];
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = '3. FOOD & CATERING PLAN';
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = 'Selected Package: '.($food['selected_package'] ?? 'None selected');
        $breakdown = $food['headcount_breakdown'] ?? [];
        $students = $breakdown['students'] ?? 0;
        $staff = $breakdown['staff'] ?? 0;
        $judges = $breakdown['judges'] ?? 0;
        $totalHeads = $breakdown['total'] ?? ($students + $staff + $judges);
        $lines[] = "Headcount Breakdown: {$students} students + {$staff} staff + {$judges} judges = {$totalHeads} total";
        $lines[] = 'Unit Price: Rs '.number_format((float) ($food['unit_cost'] ?? 0)).'/head | Delivery: Rs '.number_format((float) ($food['delivery_cost'] ?? 0));
        $lines[] = 'Total Food Cost: Rs '.number_format((float) ($food['total_food_cost'] ?? 0));
        if (! empty($food['notes'])) {
            $lines[] = "Notes: {$food['notes']}";
        }
        $lines[] = '';

        // 4. Judging Plan
        $judging = $state->judging_plan ?? [];
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = '4. JUDGING PLAN';
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = 'Teams: '.($judging['total_teams'] ?? 'N/A').' | Confirmed Judges: '.($judging['confirmed_judges'] ?? 'N/A');
        $lines[] = 'Assigned Active Judges: '.($judging['assigned_judges'] ?? 'N/A').' | Standby Judges: '.($judging['standby_judges'] ?? 'N/A');
        $lines[] = 'Rules: max '.($judging['max_teams_per_judge'] ?? 3).' teams/judge, '.($judging['minutes_per_team'] ?? 15).' mins/team';
        $lines[] = 'Honorarium: Rs '.number_format((float) ($judging['honorarium_per_judge'] ?? 0)).' each | Total Judging Cost: Rs '.number_format((float) ($judging['total_judging_cost'] ?? 0));
        if (! empty($judging['contingency_notes'])) {
            $lines[] = "Contingency / Late Judge Notes: {$judging['contingency_notes']}";
        }
        $lines[] = '';

        // 5. Budget Plan
        $budget = $state->budget_plan ?? [];
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = '5. BUDGET PLAN';
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = 'Total Budget Limit: Rs '.number_format((float) ($budget['budget_limit'] ?? 0));
        $lines[] = 'Total Spent: Rs '.number_format((float) ($budget['total_spent'] ?? 0));
        $lines[] = 'Remaining Balance: Rs '.number_format((float) ($budget['remaining_balance'] ?? 0));
        $withinBudget = ($budget['within_budget'] ?? true) ? 'PASS (Within Budget)' : 'FAIL (Budget Exceeded)';
        $lines[] = "Budget Status: {$withinBudget}";

        if (! empty($budget['line_items']) && is_array($budget['line_items'])) {
            $lines[] = 'Line Items:';
            foreach ($budget['line_items'] as $item) {
                $cat = $item['category'] ?? 'Expense';
                $desc = $item['description'] ?? '';
                $amt = number_format((float) ($item['amount'] ?? 0));
                $lines[] = "  * [{$cat}] {$desc}: Rs {$amt}";
            }
        }
        if (! empty($budget['excluded_items']) && is_array($budget['excluded_items'])) {
            $lines[] = 'Excluded / Rejected Items:';
            foreach ($budget['excluded_items'] as $ex) {
                $desc = is_array($ex) ? ($ex['reason'] ?? ($ex['description'] ?? json_encode($ex))) : (string) $ex;
                $lines[] = "  * Excluded: {$desc}";
            }
        }
        $lines[] = '';

        // 6. Validation Summary
        $summary = $state->validation_summary ?? [];
        $lines[] = '================================================================================';
        $lines[] = '                              VALIDATION SUMMARY                                ';
        $lines[] = '================================================================================';
        $checks = [
            'correct_figures' => '1. Correct Figures (Formulas & Math)',
            'within_budget' => '2. Within Budget Limit',
            'venue_fits' => '3. Venue Fits Headcount',
            'schedule_valid' => '4. Schedule Valid (Within Duration)',
            'enough_judges' => '5. Enough Confirmed Judges Available',
            'all_areas_covered' => '6. All Areas Covered (Venue, Schedule, Food, Judging, Budget)',
        ];

        foreach ($checks as $key => $label) {
            $status = ($summary[$key] ?? false) ? 'PASS' : 'FAIL';
            $lines[] = "- {$label}: [{$status}]";
        }
        $lines[] = '';

        // 7. Rejected / Superseded Messages
        $lines[] = '================================================================================';
        $lines[] = '                        REJECTED / SUPERSEDED MESSAGES                          ';
        $lines[] = '================================================================================';
        $flaggedMessages = $state->ingestedMessages()
            ->whereIn('status', ['rejected', 'superseded'])
            ->get();

        if ($flaggedMessages->isEmpty()) {
            $lines[] = 'None. All ingested messages were valid and accepted.';
        } else {
            foreach ($flaggedMessages as $msg) {
                $idStr = $msg->raw_index ? "Message #{$msg->raw_index}" : "Message ID {$msg->id}";
                if ($msg->sender) {
                    $idStr .= " ({$msg->sender})";
                }
                $reason = $msg->rejection_reason ?? 'Rejected';
                $statusUpper = strtoupper($msg->status);
                $lines[] = "- [{$statusUpper}] {$idStr}: {$reason}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format a complete submission containing the formatted plan and full execution trace.
     * The very last line strictly ends with: Total Tokens: <sum>
     */
    public function formatFullSubmission(PlanState $state): string
    {
        $plan = $this->formatPlan($state);
        $trace = $this->traceFormatter->formatTrace($state);

        return $plan."\n\n".$trace;
    }
}
