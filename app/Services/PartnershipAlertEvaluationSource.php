<?php

namespace App\Services;

enum PartnershipAlertEvaluationSource: string
{
    case LogSubmit = 'log_submit';
    case ScheduledScan = 'scheduled_scan';
    case ManualDebug = 'manual_debug';
}
