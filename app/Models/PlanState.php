<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanState extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'status',
        'extracted_facts',
        'venue_plan',
        'schedule_plan',
        'food_plan',
        'judging_plan',
        'budget_plan',
        'validation_summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extracted_facts' => 'array',
            'venue_plan' => 'array',
            'schedule_plan' => 'array',
            'food_plan' => 'array',
            'judging_plan' => 'array',
            'budget_plan' => 'array',
            'validation_summary' => 'array',
        ];
    }

    /**
     * @return HasMany<IngestedMessage, $this>
     */
    public function ingestedMessages(): HasMany
    {
        return $this->hasMany(IngestedMessage::class);
    }

    /**
     * @return HasMany<LlmTrace, $this>
     */
    public function llmTraces(): HasMany
    {
        return $this->hasMany(LlmTrace::class);
    }
}
