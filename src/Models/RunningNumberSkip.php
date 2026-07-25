<?php

namespace Hasyirin\RunningNumber\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $running_number_id
 * @property int $number
 * @property string $formatted_number
 * @property string|null $reason
 * @property int $reset_cycle
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RunningNumber $runningNumber
 */
class RunningNumberSkip extends Model
{
    protected $fillable = [
        'running_number_id',
        'number',
        'formatted_number',
        'reason',
        'reset_cycle',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'reset_cycle' => 'integer',
            'used_at' => 'datetime',
        ];
    }

    public function runningNumber(): BelongsTo
    {
        return $this->belongsTo(RunningNumber::class);
    }
}
