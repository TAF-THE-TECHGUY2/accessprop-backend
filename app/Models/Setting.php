<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'review_sla_hours' => 'integer',
        'notify_on_submission' => 'boolean',
        'notify_on_funding' => 'boolean',
        'auto_activate_dashboard' => 'boolean',
        'allow_parallel_onboarding' => 'boolean',
    ];

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
            ],
        );
    }
}
