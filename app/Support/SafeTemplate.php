<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Renders admin-edited email templates without running them.
 *
 * Templates used to go through Blade::render(), which compiles to PHP. A
 * blocklist stripped @php and friends, but every `{{ expr }}` and `@if(expr)`
 * is itself a PHP expression, so anyone with an admin session could run code
 * on the server from the template preview — read .env, the Stripe keys,
 * APP_KEY. No blocklist closes that; not compiling does.
 *
 * What a template may contain is what they have actually used:
 *
 *   {{ $firstName }}                 a variable, HTML-escaped
 *   {{ $investor->email }}           one allowed field of an object
 *   {{ strtoupper($investorCode) }}  wrapped in one text function
 *   {{-- note --}}                   a comment, removed
 *
 * Anything else that looks like template syntax is refused: unsupported() lists
 * it for the editor, and render() throws rather than send an investor an
 * email with raw braces in it — the mailer falls back to the bundled view.
 */
final class SafeTemplate
{
    private const FILTERS = ['strtoupper', 'strtolower', 'ucfirst', 'ucwords', 'trim'];

    /**
     * Fields readable from an object. A model exposes every attribute, so
     * without this `{{ $investor->password }}` would print the hash, and
     * `two_factor_secret` the decrypted secret.
     */
    private const OBJECT_FIELDS = ['name', 'email', 'code', 'subject', 'category', 'body'];

    private const EXPRESSION =
        '/\{\{\s*(?:(?<filter>[a-z]+)\(\s*)?\$(?<var>[A-Za-z_]\w*)(?:->(?<field>[A-Za-z_]\w*))?\s*(?(filter)\))\s*\}\}/';

    private const COMMENT = '/\{\{--.*?--\}\}/s';

    /** Blade syntax that used to do something and now would not. */
    private const LEFTOVER =
        '/\{!!.*?!!\}|\{\{.*?\}\}|(?<![\w.@])@(?:if|elseif|else|endif|unless|endunless|isset|endisset|empty|endempty|foreach|endforeach|forelse|endforelse|for|endfor|while|endwhile|switch|case|endswitch|php|endphp|include\w*|extends|section|endsection|yield|inject|each|component|endcomponent|slot|endslot|verbatim|endverbatim|json|csrf|method|auth|endauth|guest|endguest|env|production|once|push|stack|props|aware|class|style|lang|choice|dd|dump|use)\b/s';

    public static function render(string $template, array $data): string
    {
        $template = preg_replace(self::COMMENT, '', $template);

        $rendered = preg_replace_callback(self::EXPRESSION, function (array $m) use ($data) {
            $filter = $m['filter'] ?? '';
            $field = $m['field'] ?? '';

            if ($filter !== '' && ! in_array($filter, self::FILTERS, true)) {
                throw new InvalidArgumentException("Unsupported function {$filter}() in template.");
            }

            if (! array_key_exists($m['var'], $data)) {
                throw new InvalidArgumentException("Undefined template variable \${$m['var']}.");
            }

            $value = self::resolve($data[$m['var']], $field, $m['var']);

            return e($filter !== '' ? $filter($value) : $value);
        }, $template);

        if ($leftover = self::leftovers($rendered)) {
            throw new InvalidArgumentException('Unsupported template syntax: '.$leftover[0]);
        }

        return $rendered;
    }

    /**
     * Template syntax that render() would refuse, for the editor to report
     * before saving. Unknown variables are not included: missingVariables()
     * already reports those against the sample data.
     *
     * @return list<string>
     */
    public static function unsupported(?string $template): array
    {
        if ($template === null || $template === '') {
            return [];
        }

        $template = preg_replace(self::COMMENT, '', $template);

        $problems = [];
        $template = preg_replace_callback(self::EXPRESSION, function (array $m) use (&$problems) {
            $filter = $m['filter'] ?? '';
            $field = $m['field'] ?? '';

            if ($filter !== '' && ! in_array($filter, self::FILTERS, true)) {
                $problems[] = $m[0];
            } elseif ($field !== '' && ! in_array($field, self::OBJECT_FIELDS, true)) {
                $problems[] = $m[0];
            }

            return '';
        }, $template);

        return array_values(array_unique([...$problems, ...self::leftovers($template)]));
    }

    private static function resolve(mixed $value, string $field, string $var): string
    {
        if ($field !== '') {
            if (! in_array($field, self::OBJECT_FIELDS, true)) {
                throw new InvalidArgumentException("\${$var}->{$field} is not available in templates.");
            }

            $value = data_get($value, $field);
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException("\${$var} cannot be printed directly; use one of its fields.");
    }

    /** @return list<string> */
    private static function leftovers(string $text): array
    {
        preg_match_all(self::LEFTOVER, $text, $m);

        return array_values(array_unique($m[0]));
    }
}
