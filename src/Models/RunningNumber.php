<?php

namespace Hasyirin\RunningNumber\Models;

use Hasyirin\RunningNumber\Database\Factories\RunningNumberFactory;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

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
        ];
    }

    // Saved in UTC: app-time text has no offset, so in the hour that repeats when daylight saving
    // time ends it reads back one hour early, and a boundary in that hour would reset again.
    protected function lastResetAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : Date::parse($value, 'UTC')->setTimezone(date_default_timezone_get()),
            set: fn (mixed $value) => $value === null ? null : $this->asDateTime($value)->utc()->format($this->getDateFormat()),
        )->withoutObjectCaching();
    }

    // Set here, like created_at, and not in a creating hook: seeders that use WithoutModelEvents
    // (the Laravel default) and saveQuietly() fire no model events.
    public function updateTimestamps()
    {
        if (! $this->exists) {
            $this->last_reset_at ??= now();
        }

        return parent::updateTimestamps();
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
