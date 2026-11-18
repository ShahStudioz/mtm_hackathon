<?php

namespace App\Console\Commands;

use App\Models\PlanState;
use App\Services\AreaPlannerService;
use App\Services\ConstraintSolverService;
use App\Services\MessageReaderService;
use App\Services\PlanFormatterService;
use App\Services\TraceFormatterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ProcessMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     * ZERO flags or options permitted by brief rule #2.
     *
     * @var string
     */
    protected $signature = 'plan:run {file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process an event message file and generate or update the event plan';

    public function handle(
        MessageReaderService $reader,
        ConstraintSolverService $solver,
        AreaPlannerService $planner,
        PlanFormatterService $planFormatter,
        TraceFormatterService $traceFormatter
    ): int {
        $fileArg = (string) $this->argument('file');
        $resolvedPath = $this->resolveFilePath($fileArg);

        if ($resolvedPath === null) {
            $this->error("File not found: {$fileArg}");

            return self::FAILURE;
        }

        // Detect whether active PlanState exists
        $state = PlanState::latest()->first();
        if ($state === null) {
            $state = PlanState::create([
                'status' => 'initialized',
            ]);
            $batchNumber = 1;
        } else {
            $maxBatch = (int) $state->ingestedMessages()->max('batch_number');
            $batchNumber = $maxBatch > 0 ? $maxBatch + 1 : 1;
        }

        // 1. Read message file with exactly 1 LLM call under PlanArea::Reading
        $readResult = $reader->readFile($state, $resolvedPath, $batchNumber);
        $messages = $readResult['messages'] ?? $readResult;

        // 2. Deterministic math, chronological overrides, and conflict detection
        $solverResult = $solver->processAndValidate($state, $messages, $batchNumber);

        // 3. Surgical LLM calls ONLY for affected areas
        $affectedAreas = $solverResult['affected_areas'] ?? [];
        if (! empty($affectedAreas)) {
            $state = $planner->generateAffectedAreas($state, $affectedAreas, $batchNumber);
        }

        $state->refresh();

        // 4. Render formatted outputs
        $planText = $planFormatter->formatPlan($state);
        $traceText = $traceFormatter->formatTrace($state);
        $submissionText = $planFormatter->formatFullSubmission($state);

        // 5. Output directly to console
        $this->line($submissionText);

        // 6. Write to disk in storage/app/output/
        $outputDir = storage_path('app/output');
        File::ensureDirectoryExists($outputDir);

        File::put("{$outputDir}/final_plan.txt", $planText);
        File::put("{$outputDir}/full_trace.txt", $traceText);
        File::put("{$outputDir}/submission.txt", $submissionText);

        return self::SUCCESS;
    }

    /**
     * Resolve file path across direct, base, and storage paths.
     */
    protected function resolveFilePath(string $path): ?string
    {
        $candidates = [
            $path,
            base_path($path),
            storage_path($path),
            storage_path('app/'.$path),
        ];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate) && File::isFile($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
