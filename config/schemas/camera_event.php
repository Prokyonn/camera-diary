<?php

declare(strict_types=1);

use App\Infrastructure\Seal\SealEventIndexer;
use CmsIg\Seal\Schema\Field\DateTimeField;
use CmsIg\Seal\Schema\Field\IdentifierField;
use CmsIg\Seal\Schema\Field\TextField;
use CmsIg\Seal\Schema\Index;

// Searchable fields rank in this order: a manual label beats the AI's summary.
return new Index(SealEventIndexer::INDEX, [
    'id' => new IdentifierField('id'),
    'label' => new TextField('label'),
    'summary' => new TextField('summary'),
    'subject' => new TextField('subject', filterable: true),
    'subjectName' => new TextField('subjectName', searchable: false, filterable: true),
    'individual' => new TextField('individual', searchable: false, filterable: true),
    'falseAlarmCause' => new TextField('falseAlarmCause'),
    'hints' => new TextField('hints', multiple: true),
    'camera' => new TextField('camera', searchable: false, filterable: true),
    'types' => new TextField('types', multiple: true, searchable: false, filterable: true),
    'verdict' => new TextField('verdict', searchable: false, filterable: true),
    'side' => new TextField('side', searchable: false, filterable: true),
    'status' => new TextField('status', searchable: false, filterable: true),
    'startedAt' => new DateTimeField('startedAt', filterable: true, sortable: true),
]);
