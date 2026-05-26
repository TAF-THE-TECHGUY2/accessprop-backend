<?php

return [

    'integration_key' => env('DOCUSIGN_INTEGRATION_KEY'),
    'user_id' => env('DOCUSIGN_USER_ID'),
    'account_id' => env('DOCUSIGN_ACCOUNT_ID'),

    'base_uri' => env('DOCUSIGN_BASE_URI', 'https://demo.docusign.net/restapi'),
    'auth_base_uri' => env('DOCUSIGN_AUTH_BASE_URI', 'account-d.docusign.com'),

    'private_key_path' => env('DOCUSIGN_RSA_PRIVATE_KEY_PATH', 'storage/docusign/jwt-private.key'),

    'scopes' => ['signature', 'impersonation'],

    'token_ttl_seconds' => 3300,

    'webhook_secret' => env('DOCUSIGN_WEBHOOK_SECRET'),

    'templates' => [
        'subscription_agreement' => [
            'accredited' => env('DOCUSIGN_TEMPLATE_ID_ACCREDITED', env('DOCUSIGN_SUBSCRIPTION_TEMPLATE_ID')),
            'non_accredited' => env('DOCUSIGN_TEMPLATE_ID_NON_ACCREDITED', env('DOCUSIGN_SUBSCRIPTION_TEMPLATE_ID')),
        ],
    ],

    'roles' => [
        'investor' => 'Investor',
        'counter_signer' => 'Counter Signer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tab Positions
    |--------------------------------------------------------------------------
    |
    | Tabs (signature, date, text) are added programmatically when sending each
    | envelope rather than baked into the template. Positions below are starting
    | defaults — adjust after seeing where they land on the actual PDF.
    |
    | Each entry supports:
    |   - type:        'signature' | 'date_signed' | 'text'
    |   - data_label:  the named handle (also used as the document anchor)
    |   - page:        page number; 'last' resolves to the document's last page
    |   - x, y:        pixel offsets from the top-left of the page
    |   - read_only:   only meaningful for text tabs (default true)
    |   - required:    default true
    |   - value_key:   for text tabs, which investor field provides the value
    */
    'tabs' => [
        'investor' => [
            ['type' => 'text', 'data_label' => 'investor_full_name', 'value_key' => 'name',
                'page' => 1, 'x' => 80, 'y' => 100, 'width' => 240, 'read_only' => true],
            ['type' => 'text', 'data_label' => 'investor_email', 'value_key' => 'email',
                'page' => 1, 'x' => 80, 'y' => 130, 'width' => 240, 'read_only' => true],
            ['type' => 'text', 'data_label' => 'investor_address', 'value_key' => 'address',
                'page' => 1, 'x' => 80, 'y' => 160, 'width' => 380, 'read_only' => true],
            ['type' => 'text', 'data_label' => 'investment_amount', 'value_key' => 'investment_amount',
                'page' => 1, 'x' => 80, 'y' => 190, 'width' => 180, 'read_only' => true],
            ['type' => 'text', 'data_label' => 'accreditation_status', 'value_key' => 'accreditation_status',
                'page' => 1, 'x' => 280, 'y' => 190, 'width' => 180, 'read_only' => true],
            ['type' => 'text', 'data_label' => 'investor_code', 'value_key' => 'code',
                'page' => 1, 'x' => 80, 'y' => 220, 'width' => 180, 'read_only' => true],
            ['type' => 'signature', 'data_label' => 'investor_signature',
                'page' => 1, 'x' => 80, 'y' => 500],
            ['type' => 'date_signed', 'data_label' => 'investor_signature_date',
                'page' => 1, 'x' => 350, 'y' => 510],
        ],

        'counter_signer' => [
            ['type' => 'signature', 'data_label' => 'counter_signer_signature',
                'page' => 1, 'x' => 80, 'y' => 600],
            ['type' => 'date_signed', 'data_label' => 'counter_signer_signature_date',
                'page' => 1, 'x' => 350, 'y' => 610],
        ],
    ],

    'counter_signer' => [
        'name' => env('DOCUSIGN_DEFAULT_COUNTER_SIGNER_NAME', 'Dionysios Kaskarelis'),
        'email' => env('DOCUSIGN_DEFAULT_COUNTER_SIGNER_EMAIL'),
    ],

    'storage' => [
        'disk' => env('DOCUSIGN_STORAGE_DISK', 's3'),
        'path_prefix' => 'signed-agreements',
    ],

    'connect_event_types' => [
        'sent', 'delivered', 'completed', 'declined', 'voided',
    ],

];
