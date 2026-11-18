<?php

namespace App\Http\Controllers;

use App\Models\PlanState;
use App\Services\PlanFormatterService;
use App\Services\TraceFormatterService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PlanDashboardController extends Controller
{
    public function __construct(
        protected PlanFormatterService $planFormatter,
        protected TraceFormatterService $traceFormatter
    ) {}

    /**
     * Display the hackathon AI planner live dashboard.
     */
    public function index(Request $request): Response
    {
        $state = PlanState::latest()->first();

        if ($state !== null) {
            $messages = $state->ingestedMessages()
                ->orderBy('batch_number', 'desc')
                ->orderBy('id', 'asc')
                ->get();

            $traces = $state->llmTraces()
                ->orderBy('call_number', 'asc')
                ->get();

            $totalTokens = (int) $traces->sum('total_tokens');
            $rawTraceText = $this->traceFormatter->formatTrace($state);
            $rawPlanText = $this->planFormatter->formatPlan($state);
        } else {
            $messages = [];
            $traces = [];
            $totalTokens = 0;
            $rawTraceText = 'No plan runs recorded yet. Run `php artisan plan:run <file>` to start.';
            $rawPlanText = 'No plan state initialized yet.';
        }

        return Inertia::render('dashboard', [
            'planState' => $state,
            'messages' => $messages,
            'traces' => $traces,
            'totalTokens' => $totalTokens,
            'rawTraceText' => $rawTraceText,
            'rawPlanText' => $rawPlanText,
        ]);
    }

    /**
     * Download exported plan, trace, or full submission text file.
     */
    public function export(string $type): BinaryFileResponse|HttpResponse
    {
        $validTypes = ['plan', 'trace', 'submission'];
        if (! in_array($type, $validTypes, true)) {
            abort(404, 'Invalid export type requested.');
        }

        $state = PlanState::latest()->first();

        $fileMap = [
            'plan' => [
                'path' => storage_path('app/output/final_plan.txt'),
                'filename' => 'final_plan.txt',
                'generator' => fn () => $state ? $this->planFormatter->formatPlan($state) : 'No plan found.',
            ],
            'trace' => [
                'path' => storage_path('app/output/full_trace.txt'),
                'filename' => 'full_trace.txt',
                'generator' => fn () => $state ? $this->traceFormatter->formatTrace($state) : 'No traces found.',
            ],
            'submission' => [
                'path' => storage_path('app/output/submission.txt'),
                'filename' => 'submission.txt',
                'generator' => fn () => $state ? $this->planFormatter->formatFullSubmission($state) : 'No submission found.',
            ],
        ];

        $target = $fileMap[$type];

        if (File::exists($target['path'])) {
            return response()->download($target['path'], $target['filename'], [
                'Content-Type' => 'text/plain',
            ]);
        }

        $content = call_user_func($target['generator']);

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$target['filename']}\"",
        ]);
    }
}
