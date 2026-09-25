<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Exposes an email template's prose as a list of labelled fields, and writes
 * edits back into it.
 *
 * The welcome letter is four nested tables and twenty-five inline styles
 * wrapped around seven paragraphs. The scaffolding is what makes it render in
 * Outlook; the paragraphs are the only part anyone wants to change. Editing the
 * two together in one textarea is how a live template ended up with a deleted
 * paragraph and an obscenity in it.
 *
 * Two rules shape the implementation:
 *
 *  1. Reading finds leaf elements only — ones holding text and no block child.
 *     A wrapper <td> that exists to centre a table is not prose and is never
 *     offered for editing.
 *
 *  2. Writing replaces the inner content of those elements by byte offset and
 *     touches nothing else. A DOM round-trip would re-serialise the whole
 *     document, and email HTML does not survive being tidied: entity encoding,
 *     attribute order and self-closing tags all matter to one client or
 *     another. The bytes outside a field come out exactly as they went in.
 *
 * Templates need no preparation to work here. A `data-edit` attribute supplies
 * a better label than the positional default, and `data-edit="false"` keeps
 * something like a wordmark out of the editor, but plain HTML parses fine —
 * which is what lets this run against a stored template an admin has already
 * edited, with no migration and nothing to lose.
 */
class EditableTemplate
{
    /** Elements that can hold prose. */
    private const TAGS = ['h1', 'h2', 'h3', 'p', 'td', 'li'];

    /**
     * Inline markup that survives a round trip through the editor.
     *
     * Emphasis maps to * and _ and comes back as the same tag. Anything else —
     * a styled anchor, a span carrying inline CSS, an image — cannot be
     * represented as plain text without losing what makes it work, so a region
     * containing one is not offered for guided editing at all. It stays
     * editable in the raw view, where what you see is what is stored.
     */
    private const INLINE_TAGS = ['strong', 'b', 'em', 'i', 'br'];

    /** A child of any of these means the element is scaffolding, not prose. */
    private const BLOCK_CHILDREN = ['table', 'tr', 'td', 'div', 'p', 'h1', 'h2', 'h3', 'ul', 'ol', 'li'];

    /**
     * The editable regions of a template, in document order.
     *
     * @return list<array{key: string, label: string, html: string, text: string, tag: string}>
     */
    public static function fields(?string $html): array
    {
        $fields = [];
        $paragraph = 0;

        foreach (self::regions((string) $html) as $region) {
            $label = $region['label'];

            if ($label === null) {
                $label = match ($region['tag']) {
                    'h1', 'h2', 'h3' => 'Heading',
                    'td' => 'Line',
                    'li' => 'List item',
                    default => 'Paragraph '.(++$paragraph),
                };
            }

            $fields[] = [
                'key' => $region['key'],
                'label' => $label,
                'html' => trim($region['inner']),
                'text' => self::inlineToText($region['inner']),
                'tag' => $region['tag'],
            ];
        }

        return $fields;
    }

    /**
     * Writes field values back, keyed as `fields()` returned them.
     *
     * Unknown keys are ignored rather than rejected: a template edited in the
     * raw HTML view between opening the form and saving it can legitimately
     * have lost a region, and dropping that one edit beats refusing the save.
     */
    public static function apply(string $html, array $values): string
    {
        $regions = self::regions($html);

        // Applied back to front so each replacement leaves earlier offsets valid.
        foreach (array_reverse($regions) as $region) {
            if (! array_key_exists($region['key'], $values)) {
                continue;
            }

            $replacement = self::textToInline((string) $values[$region['key']]);

            $html = substr_replace(
                $html,
                $replacement,
                $region['offset'],
                strlen($region['inner']),
            );
        }

        return $html;
    }

    /**
     * A plain-text rendering of the same template.
     *
     * Generated rather than stored so the two halves of the message cannot say
     * different things — which is what happened when they were two independent
     * textareas and only one of them got edited.
     */
    public static function toPlainText(string $html): string
    {
        $out = '';
        $previous = null;

        foreach (self::regions($html) as $region) {
            $text = self::inlineToText($region['inner']);

            if ($text === '') {
                continue;
            }

            if (in_array($region['tag'], ['h1', 'h2', 'h3'], true)) {
                $text = Str::upper($text);
                $text .= "\n".str_repeat('=', min(mb_strlen($text), 72));
            } else {
                $text = self::wrap($text);
            }

            if ($previous === null) {
                $out = $text;
                $previous = $region;

                continue;
            }

            // A rule in the letter is a rule in the text version.
            $between = substr(
                $html,
                $previous['offset'] + strlen($previous['inner']),
                $region['offset'] - $previous['offset'] - strlen($previous['inner']),
            );
            $rule = preg_match('/<hr\b/i', $between) ? "\n\n---" : '';

            // Cells sitting side by side in the same row are one block —
            // the brand and the reference belong on consecutive lines, not
            // either side of a blank one.
            $separator = ($rule === '' && $previous['tag'] === 'td' && $region['tag'] === 'td')
                ? "\n"
                : "\n\n";

            $out .= $rule.$separator.$text;
            $previous = $region;
        }

        return $out;
    }

    /**
     * Locates every prose region with its byte offset.
     *
     * Walked with a tag stack rather than matched with one expression.
     * preg_match_all cannot return nested matches: the outer <td> that centres
     * the layout consumes the paragraphs inside it, and every one of them is
     * then invisible to the scan. Pairing tags by hand is the only way to reach
     * the leaves.
     *
     * @return list<array{key: string, tag: string, label: ?string, inner: string, offset: int}>
     */
    private static function regions(string $html): array
    {
        if (! preg_match_all('/<(\/?)([a-z0-9]+)\b([^>]*?)(\/?)>/i', $html, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $open = [];
        $regions = [];
        $seen = [];

        foreach ($tags as $tag) {
            $name = strtolower($tag[2][0]);

            if (! in_array($name, self::TAGS, true)) {
                continue;
            }

            $isClosing = $tag[1][0] === '/';
            $isSelfClosing = $tag[4][0] === '/';

            if (! $isClosing) {
                if (! $isSelfClosing) {
                    $open[] = [
                        'name' => $name,
                        'attributes' => $tag[3][0],
                        'innerStart' => $tag[0][1] + strlen($tag[0][0]),
                    ];
                }

                continue;
            }

            // Unbalanced markup: discard anything opened since the last tag of
            // this name rather than mispairing the rest of the document.
            $depth = null;
            for ($i = count($open) - 1; $i >= 0; $i--) {
                if ($open[$i]['name'] === $name) {
                    $depth = $i;
                    break;
                }
            }

            if ($depth === null) {
                continue;
            }

            $element = $open[$depth];
            $open = array_slice($open, 0, $depth);

            $inner = substr($html, $element['innerStart'], $tag[0][1] - $element['innerStart']);

            if (self::hasBlockChild($inner)) {
                continue;
            }

            $label = self::label($element['attributes']);

            if ($label === false || trim(self::inlineToText($inner)) === '') {
                continue;
            }

            if (self::hasUnsupportedMarkup($inner)) {
                continue;
            }

            $regions[] = [
                'key' => self::key($name, $inner, $seen),
                'tag' => $name,
                'label' => $label,
                'inner' => $inner,
                'offset' => $element['innerStart'],
            ];
        }

        usort($regions, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        return $regions;
    }

    private static function hasUnsupportedMarkup(string $inner): bool
    {
        preg_match_all('/<\s*\/?\s*([a-z0-9]+)/i', $inner, $m);

        foreach ($m[1] ?? [] as $tag) {
            if (! in_array(strtolower($tag), self::INLINE_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function hasBlockChild(string $inner): bool
    {
        return (bool) preg_match('/<('.implode('|', self::BLOCK_CHILDREN).')\b/i', $inner);
    }

    /** The declared label, false to exclude the region, or null for the default. */
    private static function label(string $attributes): string|false|null
    {
        if (! preg_match('/\bdata-edit\s*=\s*"([^"]*)"/i', $attributes, $m)) {
            return null;
        }

        $value = trim($m[1]);

        return in_array(Str::lower($value), ['false', 'no', 'none', '-'], true) ? false : ($value ?: null);
    }

    /**
     * A stable identifier for a region.
     *
     * Derived from the tag and a hash of its content so it survives the field
     * list being rebuilt between a load and a save, and is suffixed when a
     * template repeats the same line twice.
     */
    private static function key(string $tag, string $inner, array &$seen): string
    {
        $base = $tag.'-'.substr(hash('xxh128', trim($inner)), 0, 8);
        $seen[$base] = ($seen[$base] ?? 0) + 1;

        return $seen[$base] > 1 ? $base.'-'.$seen[$base] : $base;
    }

    /** Inline HTML to the text an editor shows. */
    private static function inlineToText(string $inner): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $inner);
        $text = preg_replace('/<\s*(strong|b)\b[^>]*>(.*?)<\s*\/\s*\1\s*>/is', '*$2*', $text);
        $text = preg_replace('/<\s*(em|i)\b[^>]*>(.*?)<\s*\/\s*\1\s*>/is', '_$2_', $text);
        $text = preg_replace('/<a\b[^>]*href\s*=\s*"([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Source wrapping is presentation, not content: an editor should show
        // one paragraph as one paragraph.
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);
        $text = preg_replace('/(?<!\n)\n(?!\n)/', ' ', $text);

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
    }

    /** The editor's text back to inline HTML. */
    private static function textToInline(string $text): string
    {
        // Blade expressions pass through untouched. Escaping them is not merely
        // cosmetic: {{ $investor->email }} becomes {{ $investor-&gt;email }},
        // which is no longer valid PHP, and the template stops compiling
        // altogether. The literal text around them still has to be escaped, so
        // the string is split and only the literal parts are treated.
        $parts = preg_split(
            '/(\{\{.*?\}\}|\{!!.*?!!\}|\{\{--.*?--\}\})/s',
            trim($text),
            flags: PREG_SPLIT_DELIM_CAPTURE,
        );

        $out = '';

        foreach ($parts as $i => $part) {
            // Odd indices are the captured delimiters, i.e. the expressions.
            $out .= $i % 2 === 1
                ? $part
                : self::emphasise(
                    htmlspecialchars($part, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: false)
                );
        }

        return nl2br($out, use_xhtml: false);
    }

    /** *bold* and _italic_ back to the tags they came from. */
    private static function emphasise(string $escaped): string
    {
        $escaped = preg_replace('/\*([^*\n]+)\*/', '<strong>$1</strong>', $escaped);

        return preg_replace('/(?<![a-zA-Z0-9])_([^_\n]+)_(?![a-zA-Z0-9])/', '<em>$1</em>', $escaped);
    }

    private static function wrap(string $text): string
    {
        return implode("\n", array_map(
            fn (string $line) => wordwrap($line, 72, "\n", cut_long_words: false),
            explode("\n", $text),
        ));
    }
}
