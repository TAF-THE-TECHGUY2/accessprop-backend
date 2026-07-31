<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;

class EmailTemplate extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Blade compiles to PHP, so an admin-authored template is effectively code.
     * These directives are rejected at validation time and stripped defensively
     * here so a malicious or careless edit can't execute arbitrary PHP.
     */
    public const FORBIDDEN_PATTERNS = [
        '/@php\b/i',
        '/<\?php/i',
        '/<\?=/',
        '/@include\b/i',
        '/@extends\b/i',
        '/@inject\b/i',
        '/@each\b/i',
    ];

    /**
     * Returns the active DB template for a key, or null so the caller can fall
     * back to the bundled Blade view.
     */
    public static function forKey(string $key): ?self
    {
        return static::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->first();
    }

    public static function containsForbiddenSyntax(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    public function renderSubject(array $data): string
    {
        return $this->renderString($this->subject, $data);
    }

    public function renderHtml(array $data): string
    {
        return $this->renderString($this->body_html, $data);
    }

    public function renderText(array $data): ?string
    {
        if (blank($this->body_text)) {
            return null;
        }

        return $this->renderString($this->body_text, $data);
    }

    /**
     * Names of {{ $variables }} referenced by the template that were not
     * supplied — used by the admin UI to warn before saving.
     */
    public function missingVariables(array $data): array
    {
        $source = implode("\n", [$this->subject, $this->body_html, (string) $this->body_text]);
        preg_match_all('/\{\{\s*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $source, $matches);

        $referenced = array_unique($matches[1] ?? []);

        return array_values(array_diff($referenced, array_keys($data)));
    }

    private function renderString(string $template, array $data): string
    {
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            $template = preg_replace($pattern, '', $template);
        }

        return Blade::render($template, $data, deleteCachedView: true);
    }
}
