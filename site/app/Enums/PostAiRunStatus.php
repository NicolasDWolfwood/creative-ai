<?php

namespace App\Enums;

enum PostAiRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Stale = 'stale';
    case Cancelled = 'cancelled';
    case Dismissed = 'dismissed';
    case Applied = 'applied';
}
