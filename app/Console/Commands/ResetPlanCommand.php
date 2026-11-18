<?php

namespace App\Console\Commands;

use App\Models\IngestedMessage;
use App\Models\LlmTrace;
use App\Models\PlanState;
use Illuminate\Console\Command;

class ResetPlanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plan:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanly reset plan states, ingested messages, and LLM traces';

    public function handle(): int
    {
        LlmTrace::query()->delete();
        IngestedMessage::query()->delete();
        PlanState::query()->delete();

        $this->info('Plan database cleanly reset.');

        return self::SUCCESS;
    }
}
