<?php

namespace App\Support;

/**
 * Whitelist sanitiser for the rich-text fragments the PDF editor stores in
 * a company layout template (edited headings, closing text, textboxes …).
 *
 * Only inline formatting survives: b/strong/i/em/u/br/span/div/p/font with
 * a filtered `style` attribute. Every other element is unwrapped (its text
 * is kept), every other attribute is dropped, and style declarations that
 * could load resources or run script (url(), expression(), javascript:,
 * behaviour, @import) are removed. The client sanitises again with
 * DOMPurify before injecting — defence in depth.
 */
final class PdfHtmlSanitizer
{
    // Tables are needed for the 3-column legal footer (footer-legal-table).
    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 'br', 'span', 'div', 'p', 'font', 'sup', 'sub',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th'];

    private const ALLOWED_CSS = [
        'color', 'background', 'background-color', 'font-size', 'font-weight', 'font-style',
        'font-family', 'text-decoration', 'text-align', 'line-height', 'letter-spacing',
        'text-transform', 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
        'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
    ];

    public static function html(?string $html, int $maxLen = 8000): string
    {
        $html = (string) $html;
        if ($html === '') return '';
        if (strlen($html) > $maxLen) $html = substr($html, 0, $maxLen);

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__root');
        if (!$root) return '';
        self::cleanChildren($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) $out .= $doc->saveHTML($child);
        return $out;
    }

    /** Filters a raw `style` attribute value down to safe declarations. */
    public static function css(?string $style): string
    {
        $keep = [];
        foreach (explode(';', (string) $style) as $decl) {
            if (!str_contains($decl, ':')) continue;
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            if (!in_array($prop, self::ALLOWED_CSS, true)) continue;
            if (preg_match('/url\s*\(|expression|javascript:|behavior|@import|[<>{}\\\\]/i', $val)) continue;
            if (strlen($val) > 120) continue;
            $keep[] = $prop . ':' . $val;
        }
        return implode(';', $keep);
    }

    private static function cleanChildren(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) continue;
            if (!$child instanceof \DOMElement) { $node->removeChild($child); continue; }

            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template'], true)) {
                $node->removeChild($child);
                continue;
            }
            self::cleanChildren($child);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $style = $child->getAttribute('style');
            $class = $child->getAttribute('class');
            $span  = ['colspan' => $child->getAttribute('colspan'), 'rowspan' => $child->getAttribute('rowspan')];
            foreach (iterator_to_array($child->attributes) as $attr) $child->removeAttribute($attr->name);
            $clean = self::css($style);
            if ($clean !== '') $child->setAttribute('style', $clean);
            // Layout classes of the document markup (e.g. footer-line) — plain tokens only.
            if ($class !== '' && preg_match('/^[A-Za-z0-9_ -]{1,120}$/', $class)) $child->setAttribute('class', $class);
            foreach ($span as $name => $v) {
                if (in_array($tag, ['td', 'th'], true) && preg_match('/^[1-9][0-9]?$/', $v)) $child->setAttribute($name, $v);
            }
        }
    }
}
