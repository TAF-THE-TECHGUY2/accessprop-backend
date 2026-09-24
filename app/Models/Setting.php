<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class Setting extends Model
{
    /**
     * Fallbacks for the create-account page's legal links, matched by the
     * migration's column defaults and by the React bundle's own fallback.
     */
    public const DEFAULT_TERMS_OF_USE_URL = 'https://www.ap.boston/terms-of-use';

    public const DEFAULT_PRIVACY_POLICY_URL = 'https://www.ap.boston/privacy-policy';

    /**
     * Sender identity fallbacks, used when the row can't be read. They mirror
     * what MAIL_FROM_* shipped with so behaviour is unchanged by default.
     */
    public const DEFAULT_MAIL_FROM_NAME = 'Access Properties';

    public const DEFAULT_MAIL_FROM_ADDRESS = 'hello@mg.ap.boston';

    public const DEFAULT_MAIL_REPLY_TO_ADDRESS = 'hello@ap.boston';

    protected $guarded = ['id'];

    protected $casts = [
        'review_sla_hours' => 'integer',
        'notify_on_submission' => 'boolean',
        'notify_on_funding' => 'boolean',
        'auto_activate_dashboard' => 'boolean',
        'allow_parallel_onboarding' => 'boolean',
        'demo_payments_enabled' => 'boolean',
    ];

    /**
     * Read-only lookup for hot paths like addressing an outgoing email, where
     * singleton()'s firstOrCreate would mean a write on every send — and would
     * throw on an unreachable or not-yet-migrated database.
     */
    public static function current(): ?self
    {
        try {
            return static::query()->find(1);
        } catch (Throwable) {
            return null;
        }
    }

    public static function singleton(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'organization_name' => 'Access Properties',
                'api_environment' => 'Sandbox',
                'review_sla_hours' => 24,
                'notify_on_submission' => true,
                'notify_on_funding' => true,
                'auto_activate_dashboard' => false,
                'support_email' => 'ops@accessproperties.com',
                'default_country' => 'United States',
                'allow_parallel_onboarding' => false,
                'demo_payments_enabled' => false,
                'terms_of_use_url' => self::DEFAULT_TERMS_OF_USE_URL,
                'privacy_policy_url' => self::DEFAULT_PRIVACY_POLICY_URL,
                'mail_from_name' => self::DEFAULT_MAIL_FROM_NAME,
                'mail_from_address' => self::DEFAULT_MAIL_FROM_ADDRESS,
                'mail_reply_to_address' => self::DEFAULT_MAIL_REPLY_TO_ADDRESS,
            ],
        );
    }
}
