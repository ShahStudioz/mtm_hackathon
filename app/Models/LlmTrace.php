<?php

namespace App\Models;

use App\Enums\PlanArea;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmTrace extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'plan_state_id',
        'batch_number',
        'call_number',
        'model_name',
        'area',
        'input_payload',
        'output_payload',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_number' => 'integer',
            'call_number' => 'integer',
            'area' => PlanArea::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PlanState, $this>
     */
    public function planState(): BelongsTo
    {
        return $this->belongsTo(PlanState::class);
    }
}
