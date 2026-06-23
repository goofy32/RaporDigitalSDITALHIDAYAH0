<?php

namespace App\Services;

use ZipArchive;

class ReportIdentityLayoutStabilizer
{
    private const MIN_TEXTBOX_HEIGHT_EMU = 431800; // 34pt
    private const MIN_TEXTBOX_HEIGHT_PT = 34.0;
    private const MIN_ROW_HEIGHT_TWIPS = 260;

    public function stabilize(string $docxPath): bool
    {
        if (! class_exists(ZipArchive::class) || ! is_file($docxPath)) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            return false;
        }

        try {
            $xml = $zip->getFromName('word/document.xml');

            if ($xml === false) {
                return false;
            }

            $updatedXml = $this->stabilizeDocumentXml($xml);

            if ($updatedXml === $xml) {
                return false;
            }

            return $zip->addFromString('word/document.xml', $updatedXml);
        } finally {
            $zip->close();
        }
    }

    public function stabilizeDocumentXml(string $xml): string
    {
        $xml = preg_replace_callback(
            '/<wps:wsp\b.*?<\/wps:wsp>/s',
            fn (array $matches): string => $this->isIdentityBlock($matches[0])
                ? $this->relaxIdentityBlock($matches[0])
                : $matches[0],
            $xml
        ) ?? $xml;

        return preg_replace_callback(
            '/<v:shape\b.*?<\/v:shape>/s',
            fn (array $matches): string => $this->isIdentityBlock($matches[0])
                ? $this->relaxIdentityBlock($matches[0])
                : $matches[0],
            $xml
        ) ?? $xml;
    }

    private function isIdentityBlock(string $xmlBlock): bool
    {
        $text = html_entity_decode(strip_tags($xmlBlock), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return (
            str_contains($text, 'Nama') &&
            str_contains($text, 'NISN/NIS')
        ) || (
            str_contains($text, 'Kelas') &&
            str_contains($text, 'Tahun') &&
            str_contains($text, 'Pelajaran')
        );
    }

    private function relaxIdentityBlock(string $xmlBlock): string
    {
        $xmlBlock = str_replace('w:lineRule="exact"', 'w:lineRule="auto"', $xmlBlock);
        $xmlBlock = $this->ensureDrawingTextBoxHeight($xmlBlock);
        $xmlBlock = $this->ensureFallbackTextBoxHeight($xmlBlock);

        return $this->ensureMinimumRowHeights($xmlBlock);
    }

    private function ensureDrawingTextBoxHeight(string $xmlBlock): string
    {
        return preg_replace_callback(
            '/<a:ext\b([^>]*\bcx="[^"]+"[^>]*\bcy=")(\d+)("[^>]*)\/>/',
            function (array $matches): string {
                $height = max((int) $matches[2], self::MIN_TEXTBOX_HEIGHT_EMU);

                return '<a:ext' . $matches[1] . $height . $matches[3] . '/>';
            },
            $xmlBlock
        ) ?? $xmlBlock;
    }

    private function ensureFallbackTextBoxHeight(string $xmlBlock): string
    {
        return preg_replace_callback(
            '/style="([^"]*)"/',
            function (array $matches): string {
                $style = preg_replace_callback(
                    '/height:\s*([0-9.]+)pt/i',
                    function (array $heightMatches): string {
                        $height = max((float) $heightMatches[1], self::MIN_TEXTBOX_HEIGHT_PT);

                        return 'height:' . rtrim(rtrim(number_format($height, 2, '.', ''), '0'), '.') . 'pt';
                    },
                    $matches[1]
                ) ?? $matches[1];

                return 'style="' . $style . '"';
            },
            $xmlBlock
        ) ?? $xmlBlock;
    }

    private function ensureMinimumRowHeights(string $xmlBlock): string
    {
        return preg_replace_callback(
            '/<w:trHeight\b([^>]*)\/>/',
            function (array $matches): string {
                $attributes = $matches[1];

                if (preg_match('/\bw:val="(\d+)"/', $attributes, $valueMatch)) {
                    $height = max((int) $valueMatch[1], self::MIN_ROW_HEIGHT_TWIPS);
                    $attributes = preg_replace('/\bw:val="\d+"/', 'w:val="' . $height . '"', $attributes) ?? $attributes;
                } else {
                    $attributes .= ' w:val="' . self::MIN_ROW_HEIGHT_TWIPS . '"';
                }

                if (preg_match('/\bw:hRule="[^"]+"/', $attributes)) {
                    $attributes = preg_replace('/\bw:hRule="[^"]+"/', 'w:hRule="atLeast"', $attributes) ?? $attributes;
                } else {
                    $attributes .= ' w:hRule="atLeast"';
                }

                return '<w:trHeight' . $attributes . '/>';
            },
            $xmlBlock
        ) ?? $xmlBlock;
    }
}
