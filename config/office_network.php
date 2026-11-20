<?php

declare(strict_types=1);

return [
    // A device counts as "online" if its open session was seen within this many seconds.
    // Keep it at about 3x the polling interval.
    'online_threshold_seconds' => (int) env('OFFICE_NETWORK_ONLINE_THRESHOLD', 180),

    // Default retention for collected records; each data source can override it.
    'default_retention_days' => (int) env('OFFICE_NETWORK_RETENTION_DAYS', 90),

    // Domain activity is stored as one row per client, domain and time bucket.
    'domain_bucket_seconds' => (int) env('OFFICE_NETWORK_DOMAIN_BUCKET', 300),

    // Longest date range a Website Activity report or export may cover.
    'max_report_days' => (int) env('OFFICE_NETWORK_MAX_REPORT_DAYS', 90),
];