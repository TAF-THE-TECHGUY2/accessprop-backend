<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

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

    /**
     * Blade comments, which render to nothing.
     *
     * `{{-- ... --}}` is not an annotation the reader sees, it is a deletion:
     * everything between the markers is removed from the email. Nothing else
     * in the editor reveals that — the variable check has no variables to
     * report, the body saves and reloads intact, and only the rendered output
     * is short. Returned here so the preview can say what is being dropped.
     *
     * @return list<array{part: string, excerpt: string, length: int}>
     */
    public function hiddenComments(): array
    {
        $found = [];

        foreach (['subject' => $this->subject, 'HTML body' => $this->body_html, 'plain text' => $this->body_text] as $part => $source) {
            if (blank($source)) {
                continue;
            }

            preg_match_all('/\{\{--(.*?)--\}\}/s', $source, $matches);

            foreach ($matches[1] ?? [] as $inner) {
                $found[] = [
                    'part' => $part,
                    'excerpt' => Str::limit(trim(preg_replace('/\s+/', ' ', $inner)), 120),
                    'length' => mb_strlen(trim($inner)),
                ];
            }
        }

        return $found;
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
        // data-edit marks a region for the admin editor. It means nothing to a
        // mail client and has no business in what the investor receives.
        return preg_replace(
            '/\s+data-edit\s*=\s*"[^"]*"/i',
            '',
            $this->renderString($this->body_html, $data),
        );
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

    /**
     * Forces Blade to treat the stored template as inline content.
     *
     * Blade::render() first asks whether the string it was given is the name of
     * an existing view, and if it is, renders that FILE instead of the text --
     * then, because of deleteCachedView, unlinks it. A template subject of
     * "welcome" therefore rendered resources/views/welcome.blade.php into the
     * subject line and deleted the file from disk. (View names are matched
     * case-insensitively on macOS, so "Welcome" did it too.)
     *
     * A leading Blade comment can never be a view name, so lookup always fails
     * and the string is compiled as content. The comment compiles to nothing,
     * leaving the rendered output byte-for-byte unchanged.
     */
    private const INLINE_GUARD = '{{-- inline --}}';

    private function renderString(string $template, array $data): string
    {
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            $template = preg_replace($pattern, '', $template);
        }

        return Blade::render(self::INLINE_GUARD.$template, $data, deleteCachedView: true);
    }
}
