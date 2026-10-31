<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Models\RunningNumber;
use Hasyirin\RunningNumber\RunningNumberManager;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('labels a Dec-14 yearly period with the year it ends in, like a working year', function () {
    RunningNumber::factory()->create([
        'type' => 'permit', 'reset_interval' => ResetInterval::YEARLY, 'reset_month' => 12, 'reset_day' => 14,
        'format' => '{number}/{period:Y}', 'padding' => 3, 'last_reset_at' => Carbon::parse('2025-12-14 00:00:00'),
    ]);
    $manager = new RunningNumberManager;
    $issued = [];

    Carbon::setTestNow('2025-12-20 09:00:00');
    $issued[] = $manager->generateFor('permit', null, null, null);
    Carbon::setTestNow('2026-01-05 09:00:00');
    $issued[] = $manager->generateFor('permit', null, null, null);
    Carbon::setTestNow('2026-12-20 09:00:00');
    $issued[] = $manager->generateFor('permit', null, null, null);
    $issued[] = $manager->generateFor('permit', null, null, null);

    expect($issued)->toBe(['001/2026', '002/2026', '001/2027', '002/2027']);
});

it('labels a calendar-year period with its own year', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'reset_interval' => ResetInterval::YEARLY, 'format' => '{period:Y}-{number}']);
    Carbon::setTestNow('2026-07-15 09:00:00');

    expect((new RunningNumberManager)->generateFor('invoice', null, null, null))->toBe('2026-00001');
});

it('labels a never-resetting type with the current date', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'format' => '{period:Y}-{number}']);
    Carbon::setTestNow('2026-07-15 09:00:00');

    expect((new RunningNumberManager)->generateFor('invoice', null, null, null))->toBe('2026-00001');
});

it('keeps period tokens on the type timezone when the output timezone is overridden', function () {
    RunningNumber::factory()->create(['type' => 'invoice', 'reset_interval' => ResetInterval::YEARLY, 'format' => '{period:Y}/{Y}']);
    Carbon::setTestNow(Carbon::parse('2026-12-31 20:00:00', 'UTC'));

    expect((new RunningNumberManager)->for('invoice')->timezone('Asia/Kuala_Lumpur')->generate())->toBe('2026/2027');
});

it('renders period tokens in previews', function () {
    RunningNumber::factory()->create([
        'type' => 'permit', 'reset_interval' => ResetInterval::YEARLY, 'reset_month' => 12, 'reset_day' => 14,
        'format' => '{number}/{period:Y}', 'padding' => 3,
    ]);
    Carbon::setTestNow('2026-12-20 09:00:00');

    expect((new RunningNumberManager)->previewFor('permit', null, null, null, null))->toBe('XXX/2027');
});

it('formats a number-only type without reading its timezone', function () {
    RunningNumber::factory()->create(['type' => 'legacy', 'timezone' => 'Asia/KualaLumpur', 'format' => '{number}', 'padding' => 3]);
    $manager = new RunningNumberManager;

    expect($manager->generateFor('legacy', null, null, null))->toBe('001')
        ->and($manager->currentFor('legacy', null, null, null))->toBe('001');
});

it('decides the reset and renders date tokens from one clock reading', function () {
    $manager = new RunningNumberManager;

    foreach (range(1, 40) as $offset) {
        Carbon::setTestNow();
        RunningNumber::factory()->create([
            'type' => "invoice-{$offset}", 'reset_interval' => ResetInterval::MONTHLY, 'number' => 119,
            'format' => '{Y}{m}-{number}', 'padding' => 4, 'last_reset_at' => Carbon::parse('2026-07-01 00:00:00'),
        ]);

        $clock = Carbon::parse('2026-07-31 23:59:59.960')->addMilliseconds($offset);
        Carbon::setTestNow(function () use (&$clock) {
            $clock = $clock->copy()->addMillisecond();

            return $clock;
        });

        expect($manager->generateFor("invoice-{$offset}", null, null, null))->toBeIn(['202607-0120', '202608-0001']);
    }
});
