<?php

use Carbon\CarbonInterface;
use Hasyirin\RunningNumber\Enums\ResetInterval;
use Illuminate\Support\Carbon;

it('never needs a reset for the NEVER case', function () {
    Carbon::setTestNow('2026-07-15 10:00:00');

    expect(ResetInterval::NEVER->needsReset(Carbon::parse('2020-01-01')))->toBeFalse();
});

it('daily resets after midnight with no anchor', function () {
    Carbon::setTestNow('2026-07-15 00:00:01');

    expect(ResetInterval::DAILY->needsReset(Carbon::parse('2026-07-14 23:00:00')))->toBeTrue();
    expect(ResetInterval::DAILY->needsReset(Carbon::parse('2026-07-15 00:00:00')))->toBeFalse();
});

it('weekly resets after the week rolls over (default Monday)', function () {
    Carbon::setTestNow('2026-07-13 00:00:01'); // a Monday

    expect(ResetInterval::WEEKLY->needsReset(Carbon::parse('2026-07-12 12:00:00')))->toBeTrue();
    expect(ResetInterval::WEEKLY->needsReset(Carbon::parse('2026-07-13 00:00:00')))->toBeFalse();
});

it('monthly resets on the 1st with no anchor', function () {
    Carbon::setTestNow('2026-08-01 00:00:01');

    expect(ResetInterval::MONTHLY->needsReset(Carbon::parse('2026-07-31 23:00:00')))->toBeTrue();
    expect(ResetInterval::MONTHLY->needsReset(Carbon::parse('2026-08-01 00:00:00')))->toBeFalse();
});

it('quarterly resets on Jan/Apr/Jul/Oct 1st with no anchor', function () {
    Carbon::setTestNow('2026-07-01 00:00:01');

    expect(ResetInterval::QUARTERLY->needsReset(Carbon::parse('2026-06-30 23:00:00')))->toBeTrue();
    expect(ResetInterval::QUARTERLY->needsReset(Carbon::parse('2026-07-01 00:00:00')))->toBeFalse();
});

it('yearly resets on Jan 1st with no anchor', function () {
    Carbon::setTestNow('2027-01-01 00:00:01');

    expect(ResetInterval::YEARLY->needsReset(Carbon::parse('2026-12-31 23:00:00')))->toBeTrue();
    expect(ResetInterval::YEARLY->needsReset(Carbon::parse('2027-01-01 00:00:00')))->toBeFalse();
});

it('yearly respects a custom month/day anchor (mbpg Dec-14 regression)', function () {
    Carbon::setTestNow('2026-07-15 10:00:00');

    // Last reset was after last year's Dec 14 anchor — no reset due.
    expect(ResetInterval::YEARLY->needsReset(Carbon::parse('2025-12-20'), month: 12, day: 14))->toBeFalse();

    // Last reset was before last year's Dec 14 anchor — reset is due.
    expect(ResetInterval::YEARLY->needsReset(Carbon::parse('2025-01-05'), month: 12, day: 14))->toBeTrue();
});

it('monthly clamps an out-of-range day to the month\'s last day', function () {
    Carbon::setTestNow('2026-03-05 00:00:00'); // February has 28 days in 2026

    // Boundary should be Feb 28, not Feb 31/March 3.
    expect(ResetInterval::MONTHLY->needsReset(Carbon::parse('2026-02-27 23:59:59'), day: 31))->toBeTrue();
    expect(ResetInterval::MONTHLY->needsReset(Carbon::parse('2026-02-28 00:00:00'), day: 31))->toBeFalse();
});

it('weekly respects a custom weekday anchor', function () {
    Carbon::setTestNow('2026-07-15 03:00:00'); // a Wednesday

    // Anchor is Monday 6am — most recent boundary is this Monday 6am (2026-07-13).
    expect(ResetInterval::WEEKLY->needsReset(Carbon::parse('2026-07-13 05:00:00'), weekday: 1, hour: 6))->toBeTrue();
    expect(ResetInterval::WEEKLY->needsReset(Carbon::parse('2026-07-13 06:00:00'), weekday: 1, hour: 6))->toBeFalse();
});

it('daily respects a custom hour anchor', function () {
    Carbon::setTestNow('2026-07-15 03:00:00');

    // Anchor is 6am — since it's only 3am, the boundary is yesterday 6am.
    expect(ResetInterval::DAILY->needsReset(Carbon::parse('2026-07-14 05:00:00'), hour: 6))->toBeTrue();
    expect(ResetInterval::DAILY->needsReset(Carbon::parse('2026-07-14 06:00:00'), hour: 6))->toBeFalse();
});

it('quarterly respects a custom phase-offset month anchor', function () {
    Carbon::setTestNow('2026-07-15 00:00:01'); // falls in the Jul-Sep 2026 quarter given a Jan-start cycle

    expect(ResetInterval::QUARTERLY->needsReset(Carbon::parse('2026-06-30 23:59:59'), month: 1, day: 1))->toBeTrue();
    expect(ResetInterval::QUARTERLY->needsReset(Carbon::parse('2026-07-01 00:00:00'), month: 1, day: 1))->toBeFalse();
});

it('resolves "now" against the given timezone, not the app default', function () {
    // App/test default timezone is UTC. At 2026-07-15 23:00 UTC, it's already
    // 2026-07-16 13:00 in Pacific/Kiritimati (UTC+14) — a full calendar day ahead.
    Carbon::setTestNow(Carbon::parse('2026-07-15 23:00:00', 'UTC'));

    // Daily anchor at midnight: under UTC, "today" is still July 15, so a reset that
    // happened on July 15 at 01:00 UTC should NOT need another reset.
    expect(ResetInterval::DAILY->needsReset(Carbon::parse('2026-07-15 01:00:00', 'UTC')))->toBeFalse();

    // Under Pacific/Kiritimati, "today" is already July 16 — the same lastResetAt
    // (July 15 01:00 UTC = July 15 15:00 Kiritimati) is now in a *previous* day, so a
    // reset IS due.
    expect(
        ResetInterval::DAILY->needsReset(Carbon::parse('2026-07-15 01:00:00', 'UTC'), timezone: 'Pacific/Kiritimati')
    )->toBeTrue();
});

it('resolves "now" against the given timezone for every interval', function (ResetInterval $interval, string $now, string $lastResetAt) {
    Carbon::setTestNow(Carbon::parse($now, 'UTC'));
    $last = Carbon::parse($lastResetAt, 'UTC');

    expect($interval->needsReset($last))->toBeFalse()
        ->and($interval->needsReset($last, timezone: 'Pacific/Kiritimati'))->toBeTrue();
})->with([
    'weekly' => [ResetInterval::WEEKLY, '2026-07-12 23:00:00', '2026-07-08 00:00:00'],
    'monthly' => [ResetInterval::MONTHLY, '2026-12-31 23:00:00', '2026-12-15 00:00:00'],
    'quarterly' => [ResetInterval::QUARTERLY, '2026-12-31 23:00:00', '2026-11-01 00:00:00'],
    'yearly' => [ResetInterval::YEARLY, '2026-12-31 23:00:00', '2026-06-01 00:00:00'],
]);

it('decides against a passed instant instead of reading the clock', function () {
    Carbon::setTestNow('2026-08-01 00:00:01');

    expect(ResetInterval::MONTHLY->needsReset(Carbon::parse('2026-07-01 00:00:00'), now: Carbon::parse('2026-07-31 23:59:59')))->toBeFalse();
});

it('finds the next boundary', function (ResetInterval $interval, string $now, array $anchors, ?string $expected) {
    $boundary = $interval->nextBoundary(...$anchors, now: Carbon::parse($now));

    expect($boundary?->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'daily, default midnight' => [ResetInterval::DAILY, '2026-07-15 10:00:00', [], '2026-07-16 00:00:00'],
    'daily, anchor hour still ahead today' => [ResetInterval::DAILY, '2026-07-15 03:00:00', ['hour' => 6], '2026-07-15 06:00:00'],
    'daily, exactly at a boundary' => [ResetInterval::DAILY, '2026-07-15 00:00:00', [], '2026-07-16 00:00:00'],
    'weekly, default Monday' => [ResetInterval::WEEKLY, '2026-07-15 10:00:00', [], '2026-07-20 00:00:00'],
    'weekly, Sunday 6am still ahead' => [ResetInterval::WEEKLY, '2026-07-19 05:00:00', ['weekday' => 0, 'hour' => 6], '2026-07-19 06:00:00'],
    'monthly, default 1st' => [ResetInterval::MONTHLY, '2026-07-15 10:00:00', [], '2026-08-01 00:00:00'],
    'monthly, day 31 clamps in February' => [ResetInterval::MONTHLY, '2026-02-10 10:00:00', ['day' => 31], '2026-02-28 00:00:00'],
    'monthly, day 31 after a clamped boundary' => [ResetInterval::MONTHLY, '2026-02-28 12:00:00', ['day' => 31], '2026-03-31 00:00:00'],
    'quarterly, default Jan/Apr/Jul/Oct' => [ResetInterval::QUARTERLY, '2026-07-15 10:00:00', [], '2026-10-01 00:00:00'],
    'quarterly, Nov phase day 30 clamps in February' => [ResetInterval::QUARTERLY, '2026-01-10 10:00:00', ['month' => 11, 'day' => 30], '2026-02-28 00:00:00'],
    'yearly, default Jan 1' => [ResetInterval::YEARLY, '2026-07-15 10:00:00', [], '2027-01-01 00:00:00'],
    'yearly, Dec 14 still ahead' => [ResetInterval::YEARLY, '2026-07-15 10:00:00', ['month' => 12, 'day' => 14], '2026-12-14 00:00:00'],
    'yearly, Dec 14 just passed' => [ResetInterval::YEARLY, '2026-12-20 10:00:00', ['month' => 12, 'day' => 14], '2027-12-14 00:00:00'],
    'yearly, Feb 29 in the next leap year' => [ResetInterval::YEARLY, '2027-03-01 10:00:00', ['month' => 2, 'day' => 29], '2028-02-29 00:00:00'],
    'never' => [ResetInterval::NEVER, '2026-07-15 10:00:00', [], null],
]);

it('finds the next boundary from the clock when no instant is passed', function () {
    Carbon::setTestNow('2026-07-15 10:00:00');

    expect(ResetInterval::DAILY->nextBoundary()->format('Y-m-d H:i:s'))->toBe('2026-07-16 00:00:00');
});

it('finds the next boundary in the given timezone', function () {
    $boundary = ResetInterval::DAILY->nextBoundary(timezone: 'Pacific/Kiritimati', now: Carbon::parse('2026-07-15 23:00:00', 'UTC'));

    expect($boundary->format('Y-m-d H:i:s e'))->toBe('2026-07-17 00:00:00 Pacific/Kiritimati');
});

it('finds the next boundary at the anchor hour after a day that skipped it', function () {
    // Atlantic/Azores skips 00:00-01:00 on 2026-03-29.
    $boundary = ResetInterval::DAILY->nextBoundary(timezone: 'Atlantic/Azores', now: Carbon::parse('2026-03-29 12:00:00', 'Atlantic/Azores'));

    expect($boundary->format('Y-m-d H:i:s P'))->toBe('2026-03-30 00:00:00 +00:00');
});

it('resets once when the anchor hour happens twice', function () {
    // America/New_York repeats 01:00-02:00 on 2026-11-01: 05:30 UTC is 01:30 EDT, 06:30 UTC is 01:30 EST.
    $resetAt = Carbon::parse('2026-11-01 05:30:00', 'UTC');

    expect(ResetInterval::DAILY->needsReset($resetAt, hour: 1, timezone: 'America/New_York', now: Carbon::parse('2026-11-01 06:30:00', 'UTC')))->toBeFalse();
});

it('finds next year\'s boundary at the first of two repeated hours', function () {
    // America/New_York repeats 01:00-02:00 on 2027-11-07.
    $boundary = ResetInterval::YEARLY->nextBoundary(month: 11, day: 7, hour: 1, timezone: 'America/New_York', now: Carbon::parse('2026-11-07 12:00:00', 'America/New_York'));

    expect($boundary->format('Y-m-d H:i:s T'))->toBe('2027-11-07 01:00:00 EDT');
});

it('keeps the date when the time of day falls in an hour that the shifted-to day skips', function () {
    // America/Nuuk skips Saturday 2026-03-28 23:00-24:00; moving 23:30 on another day to that Saturday lands in the gap.
    $lastReset = Carbon::parse('2026-03-28 10:00', 'America/Nuuk');
    $thursdayNight = Carbon::parse('2026-04-02 23:30', 'America/Nuuk');

    expect(ResetInterval::WEEKLY->needsReset($lastReset, weekday: CarbonInterface::SATURDAY, timezone: 'America/Nuuk', now: $thursdayNight))->toBeFalse()
        ->and(ResetInterval::DAILY->nextBoundary(timezone: 'America/Nuuk', now: Carbon::parse('2026-03-27 23:30', 'America/Nuuk'))->format('Y-m-d H:i'))->toBe('2026-03-28 00:00');
});

it('keeps needsReset and nextBoundary in step across daylight saving time changes', function (string $timezone, string $date) {
    $day = Carbon::parse($date, $timezone);
    $schedules = [
        [ResetInterval::DAILY, []],
        [ResetInterval::WEEKLY, ['weekday' => $day->dayOfWeek]],
        [ResetInterval::MONTHLY, ['day' => $day->day]],
        [ResetInterval::QUARTERLY, ['month' => $day->month, 'day' => $day->day]],
        [ResetInterval::YEARLY, ['month' => $day->month, 'day' => $day->day]],
    ];

    foreach ($schedules as [$interval, $anchors]) {
        foreach ([0, 1, 2, 3] as $hour) {
            for ($now = $day->copy()->utc()->subDay(); $now->lt($day->copy()->utc()->addDays(2)); $now = $now->copy()->addMinutes(30)) {
                $next = $interval->nextBoundary(...$anchors, hour: $hour, timezone: $timezone, now: $now);
                $case = "{$interval->value} at {$hour}:00, last reset {$now->toIso8601String()}, next {$next->toIso8601String()}";

                expect($interval->needsReset($now, ...$anchors, hour: $hour, timezone: $timezone, now: $next->copy()->subSecond()))->toBeFalse($case)
                    ->and($interval->needsReset($now, ...$anchors, hour: $hour, timezone: $timezone, now: $next))->toBeTrue($case);
            }
        }
    }
})->with([
    'New York, hour skipped' => ['America/New_York', '2026-03-08'],
    'New York, hour repeated' => ['America/New_York', '2026-11-01'],
    'Azores, midnight skipped' => ['Atlantic/Azores', '2026-03-29'],
    'Azores, midnight repeated' => ['Atlantic/Azores', '2026-10-25'],
    'Sydney, hour repeated' => ['Australia/Sydney', '2026-04-05'],
    'Sydney, hour skipped' => ['Australia/Sydney', '2026-10-04'],
    'Nuuk, Saturday 23:00 skipped' => ['America/Nuuk', '2026-03-28'],
]);

afterEach(fn () => Carbon::setTestNow());
