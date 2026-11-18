<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

function photo_metadata_values(): array
{
    static $values = null;
    if ($values === null) {
        $values = [];
        foreach (['author', 'copyright', 'credit', 'license'] as $key) {
            $values[$key] = trim((string)setting('photo_' . $key));
        }
    }
    return $values;
}

function photo_metadata_xmp(array $values): string
{
    $xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $items = '';
    if ($values['author'] !== '') {
        $items .= '<dc:creator><rdf:Seq><rdf:li>' . $xml($values['author']) . '</rdf:li></rdf:Seq></dc:creator>';
    }
    if ($values['copyright'] !== '') {
        $items .= '<dc:rights><rdf:Alt><rdf:li xml:lang="x-default">' . $xml($values['copyright']) . '</rdf:li></rdf:Alt></dc:rights>';
    }
    if ($values['credit'] !== '') {
        $items .= '<photoshop:Credit>' . $xml($values['credit']) . '</photoshop:Credit>';
    }
    if ($values['license'] !== '') {
        $items .= '<xmpRights:UsageTerms><rdf:Alt><rdf:li xml:lang="x-default">' . $xml($values['license']) . '</rdf:li></rdf:Alt></xmpRights:UsageTerms>';
    }
    return '<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/">'
        . $items . '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
}

function photo_metadata_write(string $path, string $blob): bool
{
    $tmp = $path . '.meta-' . getmypid();
    if (file_put_contents($tmp, $blob, LOCK_EX) === false) {
        return false;
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function photo_metadata_jpeg(string $blob, array $values, string $xmp): string
{
    $app1 = "http://ns.adobe.com/xap/1.0/\0" . $xmp;
    $segments = "\xff\xe1" . pack('n', strlen($app1) + 2) . $app1;
    $iptc = '';
    foreach ([80 => 'author', 110 => 'credit', 116 => 'copyright'] as $dataset => $key) {
        $value = $values[$key];
        if ($value !== '') {
            $iptc .= "\x1c" . pack('n', (2 << 8) | $dataset) . pack('n', strlen($value)) . $value;
        }
    }
    if ($iptc !== '') {
        $irb = "8BIM" . pack('n', 0x0404) . "\0\0" . pack('N', strlen($iptc)) . $iptc . (strlen($iptc) % 2 ? "\0" : '');
        $app13 = "Photoshop 3.0\0" . $irb;
        $segments .= "\xff\xed" . pack('n', strlen($app13) + 2) . $app13;
    }
    return substr($blob, 0, 2) . $segments . substr($blob, 2);
}

function photo_metadata_png(string $blob, string $xmp): string
{
    $data = "XML:com.adobe.xmp\0\0\0\0\0" . $xmp;
    $chunk = pack('N', strlen($data)) . 'iTXt' . $data . pack('N', crc32('iTXt' . $data));
    $pos = strrpos($blob, "\0\0\0\0IEND");
    return $pos === false ? $blob : substr($blob, 0, $pos) . $chunk . substr($blob, $pos);
}

function photo_metadata_webp(string $blob, string $path, string $xmp): string
{
    $chunk = 'XMP ' . pack('V', strlen($xmp)) . $xmp . (strlen($xmp) % 2 ? "\0" : '');
    $vp8x = strpos($blob, 'VP8X', 12);
    if ($vp8x !== false) {
        $blob[$vp8x + 8] = chr(ord($blob[$vp8x + 8]) | 0x04);
    } else {
        $size = getimagesize($path);
        if ($size) {
            $w = pack('V', (int)$size[0] - 1);
            $h = pack('V', (int)$size[1] - 1);
            $data = "\x04\0\0\0" . substr($w, 0, 3) . substr($h, 0, 3);
            $blob = substr($blob, 0, 12) . 'VP8X' . pack('V', strlen($data)) . $data . substr($blob, 12);
        }
    }
    $blob .= $chunk;
    $blob[4] = chr((strlen($blob) - 8) & 255);
    $blob[5] = chr((strlen($blob) - 8 >> 8) & 255);
    $blob[6] = chr((strlen($blob) - 8 >> 16) & 255);
    $blob[7] = chr((strlen($blob) - 8 >> 24) & 255);
    return $blob;
}

function image_metadata_embed(string $path, string $ext): bool
{
    $values = photo_metadata_values();
    if (!array_filter($values)) {
        return true;
    }
    $blob = @file_get_contents($path);
    if ($blob === false) {
        return false;
    }
    $xmp = photo_metadata_xmp($values);
    $ext = strtolower($ext);
    if ($ext === 'jpg') {
        $blob = photo_metadata_jpeg($blob, $values, $xmp);
    } elseif ($ext === 'png') {
        $blob = photo_metadata_png($blob, $xmp);
    } elseif ($ext === 'webp') {
        $blob = photo_metadata_webp($blob, $path, $xmp);
    } elseif ($ext === 'avif') {
        $uuid = hex2bin('be7acfcb97a942e89c71999491e3afac');
        $blob .= pack('N', 24 + strlen($xmp)) . 'uuid' . $uuid . $xmp;
    } else {
        return true;
    }
    return photo_metadata_write($path, $blob);
}

function image_metadata_schema(): array
{
    $values = photo_metadata_values();
    $out = [];
    if ($values['author'] !== '') {
        $out['creator'] = ['@type' => 'Person', 'name' => $values['author']];
    }
    if ($values['copyright'] !== '') {
        $out['copyrightNotice'] = $values['copyright'];
    }
    if ($values['credit'] !== '') {
        $out['creditText'] = $values['credit'];
    }
    if ($values['license'] !== '') {
        $out['license'] = $values['license'];
    }
    return $out;
}
