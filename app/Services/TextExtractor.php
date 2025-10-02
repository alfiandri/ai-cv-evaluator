<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\Element\Text as WordText;
use PhpOffice\PhpWord\Element\TextRun as WordTextRun;
use PhpOffice\PhpWord\Element\Paragraph as WordParagraph;
use PhpOffice\PhpWord\Element\Table as WordTable;
use PhpOffice\PhpWord\Element\Row as WordRow;
use PhpOffice\PhpWord\Element\Cell as WordCell;
use Smalot\PdfParser\Parser as PdfParser;

class TextExtractor
{
    public function extract(string $path, ?string $mime): string
    {
        $strict = (bool) env('TEXT_EXTRACTOR_STRICT', true);

        // 0) Guards
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return $this->failOrEmpty($strict, "File not found: {$path}");
        }
        if (filesize($path) === 0) {
            return $this->failOrEmpty($strict, "Empty file: {$path}");
        }

        $mime = strtolower($mime ?? '');
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            // 1) PDF
            if ($this->looksLikePdf($mime, $ext, $path)) {
                $parser = new PdfParser();
                $pdf = $parser->parseFile($path);
                return trim((string) $pdf->getText());
            }

            // 2) DOCX (Word 2007+ / 2010+)
            if ($this->looksLikeDocx($mime, $ext, $path)) {
                // 2a) PhpWord first (richer model, handles many cases)
                try {
                    $reader = WordIO::createReader('Word2007');
                    if (!method_exists($reader, 'canRead') || $reader->canRead($path)) {
                        $phpWord = $reader->load($path);
                        $text = $this->phpWordToText($phpWord);
                        if ($text !== '') {
                            return $text;
                        }
                    }
                } catch (\Throwable $e) {
                    throw $e;
                }

                // 2b) Zip fallback
                $xml = $this->getDocxXml($path, 'word/document.xml');
                if ($xml !== '') {
                    $plain = $this->docxXmlToText($xml);
                    if ($plain !== '') {
                        return $plain;
                    }
                }

                foreach (['word/header1.xml','word/header2.xml','word/footer1.xml','word/footer2.xml'] as $alt) {
                    $a = $this->getDocxXml($path, $alt);
                    if ($a !== '') {
                        $p = $this->docxXmlToText($a);
                        if ($p !== '') {
                            return $p;
                        }
                    }
                }

                return $this->failOrEmpty($strict, "DOCX unreadable content");
            }

            // 3) Legacy .doc or unknown types: fallback to plain text
            if ($ext === 'doc' || str_contains($mime, 'msword')) {
                return $this->failOrEmpty($strict, "Legacy .doc not supported; upload DOCX/PDF/TXT");
            }

            // 4) Fallback: plain text
            return trim((string) @file_get_contents($path));
        } catch (\Throwable $e) {
            return $this->failOrEmpty($strict, $e->getMessage());
        }
    }

    private function looksLikePdf(string $mime, string $ext, string $path): bool
    {
        if ($ext === 'pdf' || str_contains($mime, 'pdf')) return true;

        $fh = @fopen($path, 'rb');
        if ($fh) {
            $sig = fread($fh, 4);
            fclose($fh);
            if ($sig === "%PDF") return true;
        }
        return false;
    }

    private function looksLikeDocx(string $mime, string $ext, string $path): bool
    {
        if ($ext === 'docx') return true;
        if (str_contains($mime, 'officedocument.wordprocessingml.document')) return true;
        if (str_contains($mime, 'wordprocessingml')) return true;

        // check if it's a ZIP with a DOCX structure
        if ($this->zipHas($path, '[Content_Types].xml') && $this->zipHas($path, 'word/document.xml')) {
            return true;
        }
        return false;
    }

    private function zipHas(string $path, string $member): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            $exists = $zip->locateName($member) !== false;
            $zip->close();
            return $exists;
        }
        return false;
    }

    private function getDocxXml(string $path, string $member): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName($member);
            $zip->close();
            return $xml !== false ? (string) $xml : '';
        }
        return '';
    }

    // ---- Converters ---------------------------------------------------------

    private function phpWordToText($phpWord): string
    {
        $buf = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $el) {
                $this->collectElementText($el, $buf);
            }
            $buf[] = "\n";
        }

        $out = trim(implode('', $buf));
        // coalesce excessive newlines
        $out = preg_replace("/\n{3,}/", "\n\n", $out ?? '');
        return trim($out ?? '');
    }

    private function collectElementText($el, array &$buf): void
    {
        if ($el instanceof WordText) {
            $buf[] = $el->getText();
            return;
        }
        if ($el instanceof WordTextRun) {
            foreach ($el->getElements() as $child) {
                $this->collectElementText($child, $buf);
            }
            $buf[] = "\n";
            return;
        }
        if ($el instanceof WordParagraph) {
            foreach ($el->getElements() as $child) {
                $this->collectElementText($child, $buf);
            }
            $buf[] = "\n";
            return;
        }
        if ($el instanceof WordTable) {
            foreach ($el->getRows() as $row) {
                $this->collectElementText($row, $buf);
            }
            $buf[] = "\n";
            return;
        }
        if ($el instanceof WordRow) {
            foreach ($el->getCells() as $cell) {
                $this->collectElementText($cell, $buf);
                $buf[] = "\t";
            }
            $buf[] = "\n";
            return;
        }
        if ($el instanceof WordCell) {
            foreach ($el->getElements() as $child) {
                $this->collectElementText($child, $buf);
            }
            return;
        }

        // Fallback: try toString / getText if available
        if (method_exists($el, 'getText')) {
            $buf[] = (string) $el->getText();
            $buf[] = "\n";
        } elseif (method_exists($el, '__toString')) {
            $buf[] = (string) $el;
            $buf[] = "\n";
        }
    }

    private function docxXmlToText(string $xml): string
    {
        // Separate paragraphs to keep some structure
        $xml = preg_replace('/<w:p[^>]*>/i', "\n", $xml);

        // Extract visible text runs
        // This is more accurate than strip_tags on the whole document
        $out = '';
        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;
            // suppress warnings from bad XML
            $prev = libxml_use_internal_errors(true);
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            foreach ($xpath->query('//w:t') as $node) {
                $out .= $node->textContent;
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        } else {
            if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/si', $xml, $m)) {
                foreach ($m[1] as $t) {
                    $out .= html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5);
                }
            } else {
                $out = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5);
            }
        }

        // Normalize whitespace
        $out = preg_replace("/[ \t]+/", ' ', $out ?? '');
        $out = preg_replace("/\n{3,}/", "\n\n", $out ?? '');
        return trim($out ?? '');
    }

    private function failOrEmpty(bool $strict, string $message): string
    {
        if ($strict) {
            throw new \RuntimeException($message);
        }
        \Log::warning('TextExtractor fallback', ['message' => $message]);
        return '';
    }
}
