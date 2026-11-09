<?php
/**
 * Copy to config.php on the server and fill in the secrets.
 * Column names below must match the Smartsheet column titles exactly (IDs are resolved at runtime).
 */
return [
    // Shared secret LP must send as header "x-webhook-key" (or ?key=) on every call.
    'inbound_key' => 'CHANGE-ME-long-random-string',

    // Log everything, write nothing. Flip to false when going live.
    'dry_run' => true,

    // Separate key for dashboard.php (open dashboard.php?key=... once; a cookie keeps you signed in).
    'dashboard_key' => 'CHANGE-ME-another-long-random-string',
    // SQLite event store behind the dashboard. Lives in data/ under an unguessable file name.
    'store' => ['enabled' => true, 'dir' => __DIR__ . '/data', 'retention_days' => 400],

    'log'   => ['dir' => __DIR__ . '/logs', 'full_payload' => false],
    'alert' => ['slack_webhook' => ''],   // optional Slack incoming webhook URL for error alerts

    'smartsheet' => [
        'transport' => 'proxy',            // 'proxy' (via Make) or 'direct' (bearer token)
        'proxy' => [
            'url'    => 'https://hook.us2.make.com/CHANGE-ME',
            'secret' => 'CHANGE-ME-proxy-secret',
        ],
        'direct' => [
            'base'  => 'https://api.smartsheet.com/2.0',
            'token' => '',
        ],
        'column_cache_ttl' => 21600,
    ],

    'staff_keywords' => ['contractor', 'educator', 'independent', 'staff', 'program manager'],

    'sheets' => [

        // ---------------------------------------------------------------- schools
        'schools' => [
            'sheet'           => ['id' => 6023663445823364],   // 1. LEARN | Program Launch Tracker
            'require'         => ['rule' => 'sowUrl'],
            'reject_if_staff' => true,
            'staff_keywords'  => ['contractor', 'educator', 'independent', 'staff', 'program manager'],
            'find' => [
                ['label' => 'opportunity id', 'equals' => ['LP Opportunity ID' => '{{customData."LP Opportunity ID"}}']],
                ['label' => 'contact id',     'equals' => ['LP Contact ID' => '{{contact_id}}']],
                ['label' => 'school name',    'equals' => ['SCHOOL NAME' => ['rule' => 'schoolName']], 'empty' => ['LP Opportunity ID', 'LP Contact ID']],
            ],
            'create'      => true,
            'write_empty' => false,
            'map' => [
                'LP First Name'                  => '{{customData."LP First Name"}}',
                'LP Middle Name'                 => '{{customData."LP Middle Name"}}',
                'LP Last Name'                   => '{{customData."LP Last Name"}}',
                'LP Email'                       => '{{customData."LP Email"}}',
                'LP Phone'                       => '{{customData."LP Phone"}}',
                'LP Contact Type'                => '{{contact_type}}',
                'LP Contact Source'              => '{{customData."LP Contact Source"}}',
                'LP Title'                       => '{{customData."LP Title"}}',
                'LP Street Address'              => '{{customData."LP Street Address"}}',
                'LP City'                        => '{{customData."LP City"}}',
                'LP Country'                     => '{{customData."LP Country"}}',
                'LP State'                       => '{{customData."LP State"}}',
                'LP Postal Code'                 => '{{customData."LP Postal Code"}}',
                'LP Business Name'               => '{{customData."LP Business Name"}}',
                'LP Website'                     => '{{customData."LP Website"}}',
                'LP Time Zone'                   => '{{customData."LP Time Zone"}}',
                'LP Budget'                      => '{{customData."LP Budget"}}',
                'LP Borough'                     => '{{customData."LP Borough"}}',
                'LP Group (if teacher)'          => '{{customData."LP Group (if Teacher)"}}',
                'LP Claim form Status'           => '{{customData."LP Claim form Status"}}',
                'LP Distance (miles)'            => '{{customData."LP Distance (Miles)"}}',
                'LP Official School Name'        => '{{customData."LP Official School Name"}}',
                'LP Organization Description'    => '{{customData."LP Organization Description"}}',
                'LP County Code'                 => '{{customData."LP County Code"}}',
                'LP County'                      => '{{customData."LP County"}}',
                'LP School District Code'        => '{{customData."LP School District Code"}}',
                'LP School District Description' => '{{customData."LP School District Description"}}',
                'LP SED Code'                    => '{{customData."LP SED Code"}}',
                'LP Grade Code'                  => '{{customData."LP Grade Code"}}',
                'LP Product Category'            => '{{customData."LP Product Category"}}',
                'LP Product'                     => '{{customData."LP Product"}}',
                'LP Contract Start Date'         => '{{customData."LP Contract Start Date"}}',
                'LP Contract End Date'           => '{{customData."LP Contract End Date"}}',
                // Same LP field carries every document link; the document name decides the column.
                'LP SOW Sent'                    => ['rule' => 'sowUrl', 'unless_doc' => ['work order']],
                'LP WO Sent'                     => ['rule' => 'sowUrl', 'if_doc' => ['work order']],
                'LP Contact ID'                  => '{{contact_id}}',
                'LP Opportunity ID'              => '{{customData."LP Opportunity ID"}}',
            ],
            'map_on_create' => [
                'SCHOOL NAME'                                   => ['rule' => 'schoolName'],
            ],
        ],

        // ---------------------------------------------------------------- staff / educators
        // Mapping confirmed by Wamique (Oct 16 2026): Educator Name, Educator SOW = doc link,
        // School / Site = Business name. One row per document; the signed event updates the same row.
        'educators' => [
            'sheet'   => ['name' => 'Educator SOWs'],
            'require' => ['rule' => 'sowUrl'],
            'reject_doc_keywords'  => ['work order'],   // NYC DOE work orders share the staff workflows
            'require_doc_keywords' => ['contractor', 'educator', 'independent', 'staff', 'program manager'], // Doc Signed fires for school SOWs too
            'find' => [
                ['label' => 'sow link',      'equals' => ['Educator SOW' => ['rule' => 'sowUrl']]],
                ['label' => 'educator name', 'equals' => ['Educator Name' => ['rule' => 'fullName']], 'empty' => ['Educator SOW']],
            ],
            'create'      => true,
            'write_empty' => false,
            'map' => [
                'Educator Name' => ['rule' => 'fullName'],
                'Educator SOW'  => ['rule' => 'sowUrl'],
                'School / Site' => ['first' => ['customData."LP Business Name"', 'company_name']],
            ],
            'map_on_create' => [
                'Primary Column' => ['first' => ['customData."LP SOW Name"', 'SOW Document Name', 'full_name']],
            ],
            // Slack posts after a successful sync. Empty slack_webhook = rule off.
            'notify' => [
                [
                    'name'          => 'Program Manager SOWs -> #sow-program-managers',
                    'when_doc'      => ['program manager'],
                    'on'            => ['sent', 'signed'],     // "signed" = fired by a Doc Signed workflow
                    'slack_webhook' => '',                     // Slack incoming webhook URL for the private channel
                    'title'         => 'Program Manager SOW',
                ],
            ],
        ],
    ],
];
