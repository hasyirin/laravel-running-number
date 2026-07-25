<?php

namespace Hasyirin\RunningNumber\Models;

use Hasyirin\RunningNumber\Database\Factories\RunningNumberFactory;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property ResetInterval $reset_interval
 * @property int|null $reset_month
 * @property int|null $reset_day
 * @property int|null $reset_weekday
 * @property int|null $reset_hour
 * @property string|null $timezone
 * @property int $reset_cycle
 * @property UndoMode $undo_mode
 * @property string|null $format
 * @property int $number
 * @property int $padding
 * @property Carbon $last_reset_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, RunningNumberSkip> $skips
 * @property-read Collection<int, RunningNumberUndo> $undos
 */
class RunningNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'reset_interval',
        'reset_month',
        'reset_day',
        'reset_weekday',
        'reset_hour',
        'timezone',
        'reset_cycle',
        'undo_mode',
        'format',
        'number',
        'padding',
        'last_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'reset_interval' => ResetInterval::class,
            'undo_mode' => UndoMode::class,
            'reset_month' => 'integer',
            'reset_day' => 'integer',
            'reset_weekday' => 'integer',
            'reset_hour' => 'integer',
            'reset_cycle' => 'integer',
            'number' => 'integer',
            'padding' => 'integer',
            'last_reset_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RunningNumberFactory
    {
        return RunningNumberFactory::new();
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @return HasMany<RunningNumberSkip, $this>
     */
    public function skips(): HasMany
    {
        return $this->hasMany(RunningNumberSkip::class);
    }

    /**
     * @return HasMany<RunningNumberUndo, $this>
     */
    public function undos(): HasMany
    {
        return $this->hasMany(RunningNumberUndo::class);
    }
}
