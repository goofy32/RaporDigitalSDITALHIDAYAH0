<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class ReportTemplateDocxValidationService
{
    public const UTS_TEMPLATE_TEXT_MARKER = 'RAPOR TENGAH SEMESTER';
    public const TEMPLATE_UNREADABLE_MESSAGE = 'File template tidak dapat dibaca. Pastikan file menggunakan format DOCX yang valid.';

    public function validateTypeFromDocxText(string $templatePath, string $type): ?string
    {
        try {
            $templateText = $this->normalizeDocxTemplateText(
                $this->extractDocxText($templatePath)
            );
        } catch (\Throwable $e) {
            Log::warning('Report template DOCX text could not be read.', [
                'path' => $templatePath,
                'error' => $e->getMessage(),
            ]);

            return self::TEMPLATE_UNREADABLE_MESSAGE;
        }

        $containsUtsMarker = str_contains($templateText, self::UTS_TEMPLATE_TEXT_MARKER);
        $normalizedType = strtoupper($type);

        if ($normalizedType === 'UTS' && ! $containsUtsMarker) {
            return 'File template tidak terdeteksi sebagai template UTS karena tidak memuat teks "RAPOR TENGAH SEMESTER". Silakan pilih file template UTS yang benar.';
        }

        if ($normalizedType === 'UAS' && $containsUtsMarker) {
            return 'File template terlihat sebagai template UTS karena memuat teks "RAPOR TENGAH SEMESTER", tetapi Anda memilih jenis UAS. Silakan pilih jenis UTS atau upload template UAS yang benar.';
        }

        return null;
    }

    public function extractDocxText(string $templatePath): string
    {
        $zip = new ZipArchive();

        if ($zip->open($templatePath) !== true) {
            throw new \RuntimeException('Cannot open DOCX archive.');
        }

        try {
            $parts = ['word/document.xml'];
            $mainDocumentXml = $zip->getFromName('word/document.xml');

            if (! is_string($mainDocumentXml) || $mainDocumentXml === '') {
                throw new \RuntimeException('DOCX main document XML is missing.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (is_string($name) && preg_match('/^word\/(?:header|footer)\d+\.xml$/', $name)) {
                    $parts[] = $name;
                }
            }

            $text = '';

            foreach (array_unique($parts) as $part) {
                $xml = $zip->getFromName($part);

                if (! is_string($xml) || $xml === '') {
                    continue;
                }

                $text .= ' ' . $this->extractTextFromDocxXml($xml);
            }

            return $text;
        } finally {
            $zip->close();
        }
    }

    private function extractTextFromDocxXml(string $xml): string
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();

        try {
            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \RuntimeException('Cannot parse DOCX XML part.');
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $chunks = [];

            foreach ($xpath->query('//w:t | //w:tab | //w:br | //w:cr') ?: [] as $node) {
                $chunks[] = $node->localName === 't' ? $node->textContent : ' ';
            }

            return implode('', $chunks);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalizeDocxTemplateText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::upper(trim($text));
    }
}
