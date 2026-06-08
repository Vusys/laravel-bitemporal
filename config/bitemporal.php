<?php

declare(strict_types=1);

return [
    'columns' => [
        'valid_from' => 'valid_from',
        'valid_to' => 'valid_to',
        'recorded_from' => 'recorded_from',
        'recorded_to' => 'recorded_to',
        'is_retraction' => 'is_retraction',
    ],

    'spells' => [
        'bounds' => '[)',
        'null_end_means_infinity' => true,
        'timezone' => 'UTC',
        'allow_zero_length' => false,
    ],
];
