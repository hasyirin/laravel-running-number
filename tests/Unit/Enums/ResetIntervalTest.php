<?php

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

afterEach(fn () => Carbon::setTestNow());
