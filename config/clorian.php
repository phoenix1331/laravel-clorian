<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Turn Clorian off without removing it. Models are not weighed while
    | disabled; explicit calls to Clorian::now() still run.
    |
    */

    'enabled' => (bool) env('CLORIAN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | The scoring driver: "jev" calls TypeSafe's Jev API, "fake" returns
    | scripted scores for tests and demos. JEV_API_KEY and JEV_MODEL are
    | shared with laravel-jev-moderation, so apps running both use one key
    | and one pinned model. The Jev timeout is in seconds for queued calls
    | (sync calls use sync_timeout); input over max_context_tokens is trimmed,
    | context first, and the weighing's meta records "truncated".
    |
    */

    'driver' => env('CLORIAN_DRIVER', 'jev'),

    'drivers' => [
        'jev' => [
            'key' => env('JEV_API_KEY'),
            'model' => env('JEV_MODEL', 'jev-latest'),
            'base_url' => env('JEV_BASE_URL', 'https://api.typesafe.ai/v1'),
            'timeout' => 5,
            'max_context_tokens' => 24000,
        ],
        'fake' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | "queued" weighs models in a job after the save commits. "sync" weighs
    | them inline. Sync calls use the tighter sync_timeout (seconds) and fall
    | back to the policy defaults on timeout, so a user is never blocked.
    |
    */

    'mode' => env('CLORIAN_MODE', 'queued'),

    'queue' => env('CLORIAN_QUEUE', 'default'),

    'sync_timeout' => 2.0,

    /*
    |--------------------------------------------------------------------------
    | Input storage
    |--------------------------------------------------------------------------
    |
    | Whether the redacted input is stored (encrypted) on each weighing:
    | "none", "subjectless" (only when there is no model to read it from) or
    | "always".
    |
    */

    'store_input' => env('CLORIAN_STORE_INPUT', 'subjectless'),

    /*
    |--------------------------------------------------------------------------
    | Default thresholds
    |--------------------------------------------------------------------------
    |
    | Used by any question or option that sets nothing itself. Policies
    | override these per question and per option; tuned values in the
    | clorian_thresholds table override the policy.
    |
    */

    'default_thresholds' => [
        'accept' => 0.90,
        'review' => 0.60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Calibration
    |--------------------------------------------------------------------------
    |
    | clorian:stats suggests an accept threshold for an option once at least
    | min_sample reviewed answers at or above it were upheld at the target
    | rate (0 to 1). Missed answers count against it.
    |
    */

    'calibration' => [
        'target' => 0.95,
        'min_sample' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | clorian:prune deletes weighings older than weighings_after_days and
    | clears stored input older than inputs_after_days, so stats survive
    | longer than content.
    |
    */

    'prune' => [
        'weighings_after_days' => 90,
        'inputs_after_days' => 30,
    ],

];
