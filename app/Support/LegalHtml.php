<?php

namespace App\Support;

/**
 * Strips the markup an admin should never be able to store.
 *
 * Legal copy is authored by the fund's own staff behind authentication, and the
 * portal renders it as HTML — which is how communications already work here.
 * That trust does not extend to executable markup: a stored script tag would
 * run for every investor who opens the page, and a compromised or careless
 * admin account should not be able to plant one.
 *
 * Sanitising on input rather than output, so what is stored is what is safe to
 * render, and any future consumer of this column inherits that.
 */
class LegalHtml
{
    /** Tags removed with their contents — they carry no legal wording. */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form'];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = $html;

        foreach (self::STRIP_WITH_CONTENT as $tag) {
            $clean = preg_replace('#<'.$tag.'\b[^>]*>.*?</'.$tag.'\s*>#is', '', $clean) ?? $clean;
            // An unclosed tag would otherwise survive the pair match above.
            $clean = preg_replace('#<'.$tag.'\b[^>]*/?>#i', '', $clean) ?? $clean;
        }

        // Inline handlers: onclick, onerror, onload and the rest.
        $clean = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $clean) ?? $clean;
        $clean = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $clean) ?? $clean;
        $clean = preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#i', '', $clean) ?? $clean;

        // javascript: and data: URLs in href/src.
        $clean = preg_replace('#(href|src)\s*=\s*(["\'])\s*(javascript|data|vbscript):[^"\']*\2#i', '$1="#"', $clean) ?? $clean;

        return trim($clean) === '' ? null : $clean;
    }
}
