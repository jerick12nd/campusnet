<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monitoring driver
    |--------------------------------------------------------------------------
    | mock     = simulated measurements, always shown with a DEMO MODE label.
    | snmp     = real polling of explicitly configured devices (Phase 3).
    | mikrotik = optional RouterOS API driver (Phase 3).
    |
    | The app never falls back from a real driver to mock data on failure.
    */
    'driver' => env('BANDWIDTH_MONITORING_DRIVER', 'mock'),

    'drivers' => ['mock', 'snmp', 'mikrotik'],

    /*
    |--------------------------------------------------------------------------
    | Default system settings
    |--------------------------------------------------------------------------
    | Used until an administrator saves a value in the monitoring_settings
    | table. Thresholds are configurable defaults, not universal requirements.
    */
    'defaults' => [
        'campus_name' => env('CAMPUSNET_CAMPUS_NAME', 'Campus'),
        'bandwidth_capacity_mbps' => 100,
        'monitoring_interval_seconds' => 60,
        'warning_threshold_percent' => 80,
        'critical_threshold_percent' => 90,
        'polling_timeout_seconds' => 5,
        'dashboard_refresh_seconds' => 30,
        'stale_data_minutes' => 5,
        'timezone' => 'Asia/Manila',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (applied by a scheduled prune command in a later phase)
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'traffic_samples_days' => (int) env('CAMPUSNET_RETAIN_SAMPLES_DAYS', 180),
        'audit_logs_days' => (int) env('CAMPUSNET_RETAIN_AUDIT_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log redaction
    |--------------------------------------------------------------------------
    | Any metadata key containing one of these fragments is replaced with
    | [REDACTED] before it is written to audit_logs.
    */
    'redact_keys' => [
        'password', 'secret', 'token', 'community', 'credential', 'passphrase',
        'api_key', 'apikey', 'auth_key', 'priv_key', 'private',
    ],
];