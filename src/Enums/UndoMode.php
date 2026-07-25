<?php

namespace Hasyirin\RunningNumber\Enums;

enum UndoMode: string
{
    case RECLAIM = 'reclaim';
    case RECORD = 'record';
}
