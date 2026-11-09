<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

/**
 * Whitelist-based HTML sanitizer for rich-text fields edited in the WYSIWYG editor.
 *
 * Anything not in the allow-list is removed: unknown elements are unwrapped
 * (their text is kept), dangerous ones are dropped with their content, and
 * every attribute except a safe `href` is stripped. The output is normalised
 * so the same content always serialises the same way.
 */
class HtmlSanitizer
{
    /** Elements kept as-is (after alias normalisation). */
    public const ALLOWED = ['p', 'br', 'hr', 'strong', 'em', 'u', 's', 'h2', 'h3', 'ul', 'ol', 'li', 'a', 'blockquote'];

    /** Elements that are renamed to an allowed equivalent. */
    private const ALIASES = [
        'b' => 'strong',
        'i' => 'em',
        'strike' => 's',
        'del' => 's',
        'ins' => 'u',
        'h1' => 'h2',
        'h4' => 'h3',
        'h5' => 'h3',
        'h6' => 'h3',
        'div' => 'p',
        'section' => 'p',
        'article' => 'p',
        'header' => 'p',
        'footer' => 'p',
        'main' => 'p',
        'aside' => 'p',
        'pre' => 'p',
        'figure' => 'p',
        'figcaption' => 'p',
        'address' => 'p',
        'td' => 'p',
        'th' => 'p',
        'dd' => 'p',
        'dt' => 'p',
    ];

    /** Elements removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math', 'template',
        'noscript', 'head', 'title', 'meta', 'link', 'base', 'textarea', 'select', 'option', 'input', 'button', 'form',
        'canvas', 'audio', 'video', 'img', 'picture', 'source', 'track', 'map', 'area',
    ];

    private const BLOCKS = ['p', 'h2', 'h3', 'ul', 'ol', 'blockquote', 'hr'];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Clean a fragment of HTML. Returns null when nothing readable is left.
     */
    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="rt-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('rt-root');

        if ($root === null) {
            return null;
        }

        $this->cleanChildren($root);
        $this->flattenNestedBlocks($root);
        $this->fixLists($root);
        $this->wrapLooseInlineContent($root);
        $this->dropEmptyParagraphs($root);

        if (trim($this->textOf($root)) === '' && $this->countTags($root, 'hr') === 0) {
            return null;
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out) === '' ? null : trim($out);
    }

    /**
     * Plain-text rendering of a cleaned fragment, for places that cannot show HTML.
     */
    public function toText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = preg_replace('~<br\s*/?>~i', "\n", $html);
        $text = preg_replace('~</(p|h2|h3|li|blockquote)>~i', "\n", $text);
        $text = preg_replace('~<li[^>]*>~i', '• ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMText) {
                continue;
            }

            if (! $node instanceof DOMElement) {
                $parent->removeChild($node); // comments, processing instructions, CDATA

                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP, true)) {
                $parent->removeChild($node);

                continue;
            }

            $tag = self::ALIASES[$tag] ?? $tag;

            if (! in_array($tag, self::ALLOWED, true)) {
                // Unknown element: keep its children in place, drop the wrapper.
                $this->cleanChildren($node);
                $this->unwrap($node);

                continue;
            }

            if ($tag === 'a' && $this->safeHref($node->getAttribute('href')) === null) {
                // A link without a usable destination becomes plain text.
                $this->cleanChildren($node);
                $this->unwrap($node);

                continue;
            }

            $element = $this->rebuild($node, $tag);
            $this->cleanChildren($element);
        }
    }

    /**
     * Replace an element with a fresh one of the given tag carrying only safe attributes.
     */
    private function rebuild(DOMElement $node, string $tag): DOMElement
    {
        $element = $node->ownerDocument->createElement($tag);

        if ($tag === 'a') {
            $element->setAttribute('href', $this->safeHref($node->getAttribute('href')));
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }

        while ($node->firstChild !== null) {
            $element->appendChild($node->firstChild);
        }

        $node->parentNode->replaceChild($element, $node);

        return $element;
    }

    private function safeHref(string $href): ?string
    {
        $href = trim(preg_replace('/[\x00-\x20]+/', '', $href) ?? '');

        if ($href === '' || Str::startsWith($href, '//')) {
            return null;
        }

        if (Str::startsWith($href, '/') && ! Str::startsWith($href, '//')) {
            return $href;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if ($scheme === '') {
            return Str::contains($href, '.') ? 'https://'.$href : null;
        }

        return in_array($scheme, self::SAFE_SCHEMES, true) ? $href : null;
    }

    private function unwrap(DOMNode $node): void
    {
        $parent = $node->parentNode;

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    /**
     * Paragraphs (and headings) may only hold inline content. If a block element
     * ended up inside one, lift it out so the structure stays valid.
     */
    private function flattenNestedBlocks(DOMElement $root): void
    {
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach (['p', 'h2', 'h3'] as $tag) {
                foreach (iterator_to_array($root->getElementsByTagName($tag)) as $element) {
                    if ($element->parentNode !== null && $this->hasBlockChild($element)) {
                        $this->unwrap($element);
                        $changed = true;
                    }
                }
            }
        }
    }

    private function hasBlockChild(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), [...self::BLOCKS, 'li'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lists may only contain items; items outside a list become paragraphs.
     */
    private function fixLists(DOMElement $root): void
    {
        foreach (['ul', 'ol'] as $tag) {
            foreach (iterator_to_array($root->getElementsByTagName($tag)) as $list) {
                foreach (iterator_to_array($list->childNodes) as $child) {
                    if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                        continue;
                    }

                    if ($child instanceof DOMText && trim($child->wholeText) === '') {
                        $list->removeChild($child);

                        continue;
                    }

                    $li = $root->ownerDocument->createElement('li');
                    $list->replaceChild($li, $child);
                    $li->appendChild($child);
                }

                if ($list->childNodes->length === 0) {
                    $list->parentNode->removeChild($list);
                }
            }
        }

        foreach (iterator_to_array($root->getElementsByTagName('li')) as $item) {
            $parent = $item->parentNode;

            if ($parent instanceof DOMElement && in_array(strtolower($parent->tagName), ['ul', 'ol'], true)) {
                continue;
            }

            $p = $root->ownerDocument->createElement('p');
            $parent->replaceChild($p, $item);

            while ($item->firstChild !== null) {
                $p->appendChild($item->firstChild);
            }
        }
    }

    /**
     * Wrap runs of top-level text and inline elements in paragraphs so the
     * fragment always starts with a block element.
     */
    private function wrapLooseInlineContent(DOMElement $root): void
    {
        $doc = $root->ownerDocument;
        $run = null;

        foreach (iterator_to_array($root->childNodes) as $child) {
            $isBlock = $child instanceof DOMElement && in_array(strtolower($child->tagName), self::BLOCKS, true);

            if ($isBlock) {
                $run = null;

                continue;
            }

            if ($child instanceof DOMText && trim($child->wholeText) === '' && $run === null) {
                $root->removeChild($child);

                continue;
            }

            if ($run === null) {
                $run = $doc->createElement('p');
                $root->insertBefore($run, $child);
            }

            $run->appendChild($child);
        }
    }

    private function dropEmptyParagraphs(DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('p')) as $p) {
            if (trim($this->textOf($p)) === '' && $this->countTags($p, 'br') === 0) {
                $p->parentNode->removeChild($p);
            }
        }

        // Trailing blank lines carry no information.
        while (($last = $root->lastChild) instanceof DOMElement && strtolower($last->tagName) === 'p' && trim($this->textOf($last)) === '') {
            $root->removeChild($last);
        }
    }

    private function textOf(DOMNode $node): string
    {
        return str_replace("\u{A0}", ' ', $node->textContent);
    }

    private function countTags(DOMElement $element, string $tag): int
    {
        return $element->getElementsByTagName($tag)->length;
    }
}
