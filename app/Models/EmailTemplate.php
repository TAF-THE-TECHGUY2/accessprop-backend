<?php

namespace App\Models;

use App\Support\SafeTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmailTemplate extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
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

    /**
     * Template syntax the renderer will not process, for the editor to refuse.
     *
     * @return list<string>
     */
    public static function unsupportedSyntax(?string $value): array
    {
        return SafeTemplate::unsupported($value);
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

    /** See App\Support\SafeTemplate: these templates are filled in, never compiled. */
    private function renderString(string $template, array $data): string
    {
        return SafeTemplate::render($template, $data);
    }
}
