<?php
/**
 * DOCX / PDF text extraction + normalization.
 * spec: spec/parser.md — infrastructural code, no customer TZ behind it.
 * - DOCX: ZipArchive + DOMDocument (word/document.xml paragraphs).
 * - PDF:  try pdftotext if available; otherwise fall back to OCR via vision LLM (see llm.php).
 */

final class Parser {
    public static function extract(string $path, string $ext): array {
        $ext = strtolower($ext);
        if ($ext === 'docx') return self::extractDocx($path);
        if ($ext === 'pdf')  return self::extractPdf($path);
        throw new RuntimeException("Unsupported file type: $ext");
    }

    public static function extractDocx(string $path): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive not available');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('DOCX open failed');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) throw new RuntimeException('DOCX: word/document.xml missing');

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paras = [];
        foreach ($xp->query('//w:p') as $p) {
            $texts = [];
            foreach ($xp->query('.//w:t', $p) as $t) {
                $texts[] = $t->nodeValue;
            }
            $joined = trim(implode('', $texts));
            if ($joined !== '') $paras[] = $joined;
        }
        $raw = implode("\n", $paras);
        return [
            'raw' => $raw,
            'source' => 'docx',
            'quality' => self::estimateQuality($raw),
        ];
    }

    public static function extractPdf(string $path): array {
        $raw = self::tryPdfToText($path);
        if ($raw !== null && self::estimateQuality($raw) >= 0.4) {
            return ['raw' => $raw, 'source' => 'pdf-text', 'quality' => self::estimateQuality($raw)];
        }
        $ocrError = null;
        $ocr = self::tryPdfOCR($path, $ocrError);
        if ($ocr !== null) {
            return ['raw' => $ocr, 'source' => 'pdf-ocr', 'quality' => self::estimateQuality($ocr)];
        }
        if ($raw !== null) {
            return ['raw' => $raw, 'source' => 'pdf-text-lowq', 'quality' => self::estimateQuality($raw)];
        }
        $detail = $ocrError !== null ? ' — ' . $ocrError : '';
        throw new RuntimeException('PDF: no extractable text (pdftotext missing and OCR failed)' . $detail);
    }

    private static function tryPdfToText(string $path): ?string {
        $bin = self::which('pdftotext');
        if ($bin === null) return null;
        $out = tempnam(sys_get_temp_dir(), 'pdf_');
        if ($out === false) return null;
        $cmd = escapeshellcmd($bin) . ' -layout ' . escapeshellarg($path) . ' ' . escapeshellarg($out);
        @exec($cmd . ' 2>&1', $o, $code);
        $text = @file_get_contents($out);
        @unlink($out);
        if ($code === 0 && is_string($text) && $text !== '') return $text;
        return null;
    }

    private static function tryPdfOCR(string $path, ?string &$error = null): ?string {
        if (!class_exists(ToolkitLLM::class)) { $error = 'LLM class missing'; return null; }
        try {
            return ToolkitLLM::ocrPdf($path);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            error_log('[parser.tryPdfOCR] ' . $error);
            return null;
        }
    }

    private static function which(string $bin): ?string {
        $paths = ['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/'];
        foreach ($paths as $p) {
            if (is_executable($p . $bin)) return $p . $bin;
        }
        $out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        $out = is_string($out) ? trim($out) : '';
        return ($out !== '' && is_executable($out)) ? $out : null;
    }

    public static function normalize(string $raw): string {
        if ($raw === '') return '';
        $s = str_replace(["\r\n", "\r"], "\n", $raw);
        $s = preg_replace("/[\x{00A0}\x{2007}\x{202F}]/u", ' ', $s);
        $s = preg_replace("/[\x{200B}-\x{200D}\x{FEFF}]/u", '', $s);
        $s = preg_replace('/[ \t]+/u', ' ', $s);
        $s = preg_replace('/\n{3,}/', "\n\n", $s);
        $lines = array_map('trim', explode("\n", $s));
        $s = implode("\n", $lines);
        return trim($s);
    }

    /** 0..1 quality heuristic: non-whitespace density, line count, average line length. */
    public static function estimateQuality(string $raw): float {
        $len = mb_strlen($raw);
        if ($len < 200) return 0.0;
        $lines = array_filter(preg_split('/\r?\n/', $raw), fn($l) => trim($l) !== '');
        $lineCount = count($lines);
        if ($lineCount < 4) return 0.2;
        $avgLine = $len / max(1, $lineCount);
        $letters = preg_match_all('/\p{L}/u', $raw);
        $ratio = $letters ? $letters / $len : 0;
        $q = 0.0;
        $q += min(1.0, $len / 4000) * 0.3;
        $q += min(1.0, $lineCount / 40) * 0.25;
        $q += min(1.0, $ratio * 1.5) * 0.35;
        $q += ($avgLine > 12 && $avgLine < 200) ? 0.1 : 0.0;
        return round(min(1.0, $q), 3);
    }
}
