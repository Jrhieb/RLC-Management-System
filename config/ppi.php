<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PPI Intake Gate
    |--------------------------------------------------------------------------
    |
    | Public PPI submissions stay disabled until the organization has completed
    | its privacy review and given written approval for the intake. Enabling the
    | flag alone is not enough: all three notice fields must also be filled.
    |
    */

    'intake' => [
        'enabled' => env('PPI_INTAKE_ENABLED', false),
        'privacy_contact' => env('PPI_PRIVACY_CONTACT'),
        'privacy_acknowledgment' => env('PPI_PRIVACY_ACKNOWLEDGMENT'),
        'retention_notice' => env('PPI_RETENTION_NOTICE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Official Scorecard Shape
    |--------------------------------------------------------------------------
    |
    | The official PPI uses ten household questions. The import command and the
    | scoring service reject a scorecard that does not have this many questions.
    | The actual questions, response options, and points live in the database.
    |
    */

    'scorecard' => [
        'required_questions' => 10,
        'version' => 'ph-2015-fies-2018-05',
    ],

    'passing_score' => 28,
];
