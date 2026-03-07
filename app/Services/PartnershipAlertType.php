<?php

namespace App\Services;

enum PartnershipAlertType: string
{
    case ConsecutiveMisses = 'consecutive_misses';
    case StreakBroken = 'streak_broken';
    case Inactivity = 'inactivity';
    case BehindPace = 'behind_pace';
}
