<?php

namespace Hasyirin\RunningNumber\Models;

use Hasyirin\RunningNumber\Enums\UndoMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $running_number_id
 * @property int $number
 * @property string $formatted_number
 * @property UndoMode $mode
 * @property string|null $reason
 * @property int $reset_cycle
 * @property Carbon|null $reclaimed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RunningNumber $runningNumber
 */
class RunningNumberUndo extends Model
{
    protected $fillable = [
        'running_number_id',
        'number',
        'formatted_number',
        'mode',
        'reason',
        'reset_cycle',
        'reclaimed_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'mode' => UndoMode::class,
            'reset_cycle' => 'integer',
            'reclaimed_at' => 'datetime',
        ];
    }

    public function runningNumber(): BelongsTo
    {
        return $this->belongsTo(RunningNumber::class);
    }
}
