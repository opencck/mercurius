<?php

declare(strict_types=1);

namespace OpenCCK\App\Web;

use DOMDocument;
use DOMNode;
use DOMXPath;
use ZipArchive;

use function Amp\File\deleteFile;
use function Amp\File\write;

use function Mercurius\I18n\t;

/**
 * Readable text out of what the web returns: an HTML page without its scripts, styles, menus and footers, the text of
 * a DOCX or the cells of an XLSX. PDF is not read here.
 *
 * spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — text of pages and of DOCX/XLSX
 */
final class TextExtractor {
    public const MAX_CHARS = 60_000;
    private const DROP = ['script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'nav', 'footer', 'form', 'button', 'select', 'aside'];
    private const BLOCKS = ['p', 'div', 'section', 'article', 'main', 'header', 'li', 'tr', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'blockquote', 'table', 'ul', 'ol', 'dd', 'dt'];

    /** @return array{string, string} title and text; the text is null-safe and cut to MAX_CHARS */
    public function extract(string $body, string $contentType, string $url = ''): array {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? strtolower($path) : '';
        $text = match (true) {
            $type === 'text/html' || $type === 'application/xhtml+xml' || ($type === '' && stripos($body, '<html') !== false) => $this->html($body),
            str_contains($type, 'wordprocessingml') || str_ends_with($path, '.docx') => ['', $this->docx($body)],
            str_contains($type, 'spreadsheetml') || str_ends_with($path, '.xlsx') => ['', $this->xlsx($body)],
            $type === 'application/pdf' => ['', t('web.text_extractor.pdf_izvlechenie_teksta_poka_ne')],
            str_starts_with($type, 'text/') || $type === 'application/json' || $type === 'application/xml' || $type === '' => ['', $body],
            default => ['', sprintf(t('web.text_extractor.dvoichnoe_soderzhimoe_bayt'), $type, strlen($body))],
        };
        [$title, $plain] = $text;
        $plain = mb_scrub($plain, 'UTF-8');
        $plain = trim(preg_replace("~\n{3,}~", "\n\n", preg_replace('~[ \x{00A0}]+~u', ' ', $plain) ?? $plain) ?? $plain);
        if (mb_strlen($plain) > self::MAX_CHARS) {
            $plain = mb_substr($plain, 0, self::MAX_CHARS) . t('web.text_extractor.obrezano');
        }
        return [$title, $plain];
    }

    /** @return array{string, string} */
    private function html(string $html): array {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $title = trim(self::nodes($xpath, '//title')[0]->textContent ?? '');
        foreach (self::DROP as $tag) {
            foreach (self::nodes($xpath, '//' . $tag) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $root = self::nodes($xpath, '//main')[0] ?? self::nodes($xpath, '//article')[0] ?? self::nodes($xpath, '//body')[0] ?? $dom->documentElement;
        return [$title, $root === null ? '' : $this->walk($root)];
    }

    /** @return list<DOMNode> */
    private static function nodes(DOMXPath $xpath, string $query): array {
        $found = [];
        $list = $xpath->query($query);
        if ($list === false) {
            return [];
        }
        for ($i = 0; $i < $list->length; $i++) {
            $node = $list->item($i);
            if ($node instanceof DOMNode) {
                $found[] = $node;
            }
        }
        return $found;
    }

    private function walk(DOMNode $node): string {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }
        $text = '';
        for ($i = 0; $i < $node->childNodes->length; $i++) {
            $child = $node->childNodes->item($i);
            if ($child !== null) {
                $text .= $this->walk($child);
            }
        }
        $name = strtolower($node->nodeName);
        if ($name === 'a' && $node instanceof \DOMElement) {
            $href = $node->getAttribute('href');
            if (str_starts_with($href, 'http') && trim($text) !== '') {
                return trim($text) . ' (' . $href . ')';
            }
        }
        if (in_array($name, self::BLOCKS, true)) {
            $prefix = preg_match('~^h([1-6])$~', $name, $level) === 1 ? str_repeat('#', (int) $level[1]) . ' ' : ($name === 'li' ? '- ' : '');
            return "\n" . $prefix . trim($text) . "\n";
        }
        return $text;
    }

    private function docx(string $body): string {
        $xml = $this->zipEntry($body, 'word/document.xml');
        if ($xml === null) {
            return t('web.text_extractor.docx_ne_prochitan');
        }
        $xml = preg_replace(['~</w:p>~', '~<w:tab/>~', '~<w:br/>~'], ["\n", "\t", "\n"], $xml) ?? $xml;
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function xlsx(string $body): string {
        $shared = [];
        $strings = $this->zipEntry($body, 'xl/sharedStrings.xml');
        if ($strings !== null && preg_match_all('~<si>(.*?)</si>~s', $strings, $m) > 0) {
            foreach ($m[1] as $si) {
                $shared[] = html_entity_decode(strip_tags($si), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $sheet = $this->zipEntry($body, 'xl/worksheets/sheet1.xml');
        if ($sheet === null) {
            return t('web.text_extractor.xlsx_ne_prochitan');
        }
        $rows = [];
        preg_match_all('~<row[^>]*>(.*?)</row>~s', $sheet, $rowMatches);
        foreach ($rowMatches[1] as $row) {
            $cells = [];
            preg_match_all('~<c([^>]*?)(?:/>|>(.*?)</c>)~s', $row, $cellMatches, PREG_SET_ORDER);
            foreach ($cellMatches as $cell) {
                $inner = $cell[2] ?? '';
                $value = preg_match('~<v>(.*?)</v>~s', $inner, $v) === 1 ? $v[1] : '';
                if (str_contains($cell[1], 't="s"') && isset($shared[(int) $value])) {
                    $value = $shared[(int) $value];
                } elseif (preg_match('~<t[^>]*>(.*?)</t>~s', $inner, $t) === 1) {
                    $value = $t[1];
                }
                $cells[] = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $rows[] = implode("\t", $cells);
        }
        return implode("\n", $rows);
    }

    private function zipEntry(string $body, string $entry): ?string {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }
        $file = sys_get_temp_dir() . '/mercurius-doc-' . bin2hex(random_bytes(8));
        try {
            write($file, $body);
            $zip = new ZipArchive();
            if ($zip->open($file) !== true) {
                return null;
            }
            $stat = $zip->statName($entry);
            // A bomb is not opened: the entry must stay within a few megabytes.
            if ($stat === false || $stat['size'] > 20_000_000) {
                $zip->close();
                return null;
            }
            $data = $zip->getFromName($entry);
            $zip->close();
            return $data === false ? null : $data;
        } finally {
            try {
                deleteFile($file);
            } catch (\Throwable) {
            }
        }
    }
}
