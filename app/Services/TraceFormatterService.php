<?php

namespace App\Services;

use App\Enums\PlanArea;
use App\Models\PlanState;

class TraceFormatterService
{
    /**
     * Format a clean, human- and judge-readable text trace.
     */
    public function formatTrace(PlanState $state, ?int $batchNumber = null): string
    {
        $traceQuery = $state->llmTraces()->orderBy('call_number');
        if ($batchNumber !== null) {
            $traceQuery->where('batch_number', $batchNumber);
        }
        $traces = $traceQuery->get();

        $lines = [];

        foreach ($traces as $trace) {
            $areaValue = $trace->area instanceof PlanArea ? $trace->area->value : (string) $trace->area;
            $lines[] = "Call #{$trace->call_number}";
            $lines[] = "Model: {$trace->model_name}";
            $lines[] = "Area: {$areaValue}";
            $lines[] = "Input: {$trace->input_payload}";
            $lines[] = "Output: {$trace->output_payload}";
            $lines[] = "Tokens: prompt={$trace->prompt_tokens}, completion={$trace->completion_tokens}, total={$trace->total_tokens}";
            $lines[] = '';
        }

        $messageQuery = $state->ingestedMessages()
            ->where(function ($query) {
                $query->where('status', 'rejected')
                    ->orWhereNotNull('rejection_reason');
            });

        if ($batchNumber !== null) {
            $messageQuery->where('batch_number', $batchNumber);
        }

        $rejectedMessages = $messageQuery->get();

        if ($rejectedMessages->isNotEmpty()) {
            $lines[] = 'Rejected Messages:';
            foreach ($rejectedMessages as $msg) {
                $identifier = $msg->raw_index ? "Message #{$msg->raw_index}" : "Message ID {$msg->id}";
                if ($msg->sender) {
                    $identifier .= " ({$msg->sender})";
                }
                $reason = $msg->rejection_reason ?? 'Rejected';
                $lines[] = "- {$identifier}: {$reason}";
            }
            $lines[] = '';
        }

        $cumulativeTokens = (int) $traces->sum('total_tokens');
        $lines[] = "Total Tokens: {$cumulativeTokens}";

        return implode("\n", $lines);
    }
}
