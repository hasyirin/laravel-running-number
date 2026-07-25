<?php

use Hasyirin\RunningNumber\Enums\ResetInterval;
use Hasyirin\RunningNumber\Enums\UndoMode;

return [

    // If true, generate() silently registers an unknown type using the defaults below
    // instead of throwing RunningNumberTypeNotRegistered.
    'auto_register' => false,

    'default_padding' => 5,

    'default_interval' => ResetInterval::NEVER,

    'default_format' => '{number}',

    // null = fall back to config('app.timezone').
    'default_timezone' => null,

    'default_undo_mode' => UndoMode::RECLAIM,

    // Statically-declared types, upserted into the database by:
    //   php artisan running-number:sync
    'types' => [
        // 'invoice' => [
        //     'padding' => 4,
        //     'interval' => ResetInterval::YEARLY,
        //     'resetMonth' => 12,
        //     'resetDay' => 14,
        // ],
    ],

];
