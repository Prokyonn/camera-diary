<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * The configurable word lists: what cameras report, what the AI sees, what causes false alarms, and who it may know.
 */
enum VocabularyKind: string
{
    case DetectionTypes = 'detection_types';
    case Subjects = 'subjects';
    case FalseAlarmCauses = 'false_alarm_causes';
    case Individuals = 'individuals';
}
