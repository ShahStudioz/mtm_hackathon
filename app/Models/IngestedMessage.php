<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestedMessage extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'plan_state_id',
        'batch_number',
        'raw_index',
        'sender',
        'message_date',
        'raw_content',
        'status',
        'rejection_reason',
        'affected_areas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_number' => 'integer',
            'affected_areas' => 'array',
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
