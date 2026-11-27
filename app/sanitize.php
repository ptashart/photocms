<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);


function sanitize_html(string $html): string
{


    $html = trim(str_replace("\0", '', $html));
    if ($html === '') {
        return '';
    }
    $allowed = [
        'p' => [], 'br' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [],
        'a' => ['href', 'target', 'rel', 'title'], 'span' => [], 'div' => [],
    ];
    $dropWithContent = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'noscript', 'template', 'link', 'meta', 'xmp', 'plaintext', 'noembed', 'noframes'];

    $dom = new DOMDocument('1.0', 'UTF-8');

    $prevLibxml = libxml_use_internal_errors(true);




    $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prevLibxml);
    $root = $dom->getElementsByTagName('body')->item(0);
    if (!$root) {
        return '';
    }

    $clean = function (DOMNode $node) use (&$clean, $allowed, $dropWithContent): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, $dropWithContent, true)) {
                $node->removeChild($child);
                continue;
            }
            $clean($child);
            if (!isset($allowed[$tag])) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                if (!in_array(strtolower($attr->name), $allowed[$tag], true)) {
                    $child->removeAttributeNode($attr);
                }
            }
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));

                if ($href !== '' && !preg_match('~^(https?:|mailto:|tel:|/(?![/\\\\])|#)~i', $href)) {
                    $child->removeAttribute('href');
                }
                if ($child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $child->removeAttribute('target');
                }
            }
        }
    };
    $clean($root);

    $out = '';
    foreach ($root->childNodes as $n) {
        $out .= $dom->saveHTML($n);
    }
    $out = str_replace([
        '__PHOTOCMS_CSP_CORE_NONCE__', '__PHOTOCMS_CSP_CUSTOM_NONCE__',
        '<!--PHOTOCMS_CSP_BEGIN-->', '<!--PHOTOCMS_CSP_END-->',
    ], '', $out);
    return trim($out);
}

function plain_excerpt(string $html, int $max = 160): string
{
    $t = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</h1>', '</h2>', '</h3>', '</li>'], ' ', $html)), ENT_QUOTES, 'UTF-8');
    return str_limit($t, $max);
}
