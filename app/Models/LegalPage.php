<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A legal page this app owns and renders — Terms of Use, Privacy Policy.
 *
 * A page counts as live only when it has both a body and a published_at. Until
 * then the portal falls back to the external URL on `settings`, so an empty or
 * half-written page never reaches an investor.
 */
class LegalPage extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public const SLUG_TERMS = 'terms-of-use';

    public const SLUG_PRIVACY = 'privacy-policy';

    public const SLUGS = [self::SLUG_TERMS, self::SLUG_PRIVACY];

    public function isLive(): bool
    {
        return $this->published_at !== null && trim((string) $this->body_html) !== '';
    }

    /** The path the portal links to when this page is live. */
    public function path(): string
    {
        return '/legal/'.$this->slug;
    }
}
