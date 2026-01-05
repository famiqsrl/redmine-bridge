<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('REDMINE_BRIDGE_ENABLED', false),
    'base_url' => env('REDMINE_BASE_URL', 'https://redmine.example.com'),
    'api_key' => env('REDMINE_API_KEY', ''),
    'project_id' => (int) env('REDMINE_PROJECT_ID', 0),
    'tracker_id' => (int) env('REDMINE_TRACKER_ID', 0),
    'custom_fields' => [
        'origen' => env('REDMINE_CF_ORIGEN', null),
        'external_ticket_id' => env('REDMINE_CF_EXTERNAL_TICKET_ID', null),
        'canal' => env('REDMINE_CF_CANAL', null),
        'contact_ref' => env('REDMINE_CF_CONTACT_REF', null),
    ],
    'contacts_api_base' => env('REDMINE_CONTACTS_API_BASE', null),
    'contacts_search_path' => env('REDMINE_CONTACTS_SEARCH_PATH', null),
    'contacts_upsert_path' => env('REDMINE_CONTACTS_UPSERT_PATH', null),
    'contact_strategy' => env('REDMINE_CONTACT_STRATEGY', 'fallback'),
];
