<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * The configurable word lists: what cameras report, what the AI sees, and what causes false alarms.
 */
enum VocabularyKind: string
{
    case DetectionTypes = 'detection_types';
    case Subjects = 'subjects';
    case FalseAlarmCauses = 'false_alarm_causes';
}
