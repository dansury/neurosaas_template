# parser.php — DOCX / PDF text extraction

> Infrastructural module — no customer TZ behind it; no `[CODE §…]` citations (see `spec.md` §Provenance).
> Canonical source of this code: repository `site_yacloud_openrouter`.

`final class Parser` — static. Extension-driven dispatch, no MIME sniffing.

## 1. API

```php
Parser::extract(string $path, string $ext): array   // ['raw','source','quality']
Parser::extractDocx(string $path): array
Parser::extractPdf(string $path): array
Parser::normalize(string $raw): string
Parser::estimateQuality(string $raw): float         // 0.0 … 1.0
```

`extract()` lowercases `$ext`: `docx` → `extractDocx`, `pdf` → `extractPdf`, anything
else → `RuntimeException("Unsupported file type: <ext>")`.

Result shape: `['raw' => string, 'source' => string, 'quality' => float]`.

| `source` | Meaning |
|---|---|
| `docx`         | DOCX paragraphs |
| `pdf-text`     | `pdftotext` output, quality ≥ 0.4 |
| `pdf-ocr`      | OCR via `LLM::ocrPdf` |
| `pdf-text-lowq`| `pdftotext` output below the 0.4 gate, kept because OCR failed |

## 2. DOCX

`ZipArchive` → `word/document.xml` → `DOMDocument` (libxml errors suppressed and
restored) → `DOMXPath` with the `w` namespace
(`http://schemas.openxmlformats.org/wordprocessingml/2006/main`). Every `//w:p` is
flattened by concatenating its `.//w:t` nodes; blank paragraphs dropped; paragraphs
joined with `\n`.

Throws: `ZipArchive not available`, `DOCX open failed`, `DOCX: word/document.xml missing`.

## 3. PDF

1. `tryPdfToText()` — `pdftotext -layout <in> <tmp>`; binary looked up in
   `/usr/bin/`, `/usr/local/bin/`, `/opt/homebrew/bin/`, then `command -v`. Missing
   binary, non-zero exit, or empty output → `null`.
2. Text with `estimateQuality() >= 0.4` → returned as `pdf-text` immediately.
3. Otherwise `tryPdfOCR()` → `LLM::ocrPdf($path)` (skipped with reason `LLM class missing`
   when `llm.php` was not loaded; exceptions caught, logged to `error_log` as
   `[parser.tryPdfOCR] <msg>`, and turned into `null`). Non-null → `pdf-ocr`.
4. OCR failed but low-quality text exists → `pdf-text-lowq`.
5. Neither → `RuntimeException('PDF: no extractable text (pdftotext missing and OCR failed)' [— <ocr error>])`.

## 4. `normalize()`

`\r\n`/`\r` → `\n`; NBSP-class chars (`U+00A0`, `U+2007`, `U+202F`) → space; zero-width
chars (`U+200B`…`U+200D`, `U+FEFF`) removed; runs of spaces/tabs collapsed; 3+ blank
lines collapsed to one blank line; every line trimmed; whole string trimmed.

## 5. `estimateQuality()` heuristic

`< 200` chars → `0.0`; fewer than 4 non-blank lines → `0.2`. Otherwise the sum, capped
at 1.0 and rounded to 3 decimals:

| Term | Weight |
|---|---|
| `min(1, len / 4000)` | 0.30 |
| `min(1, lineCount / 40)` | 0.25 |
| `min(1, letterRatio * 1.5)` (letters = `\p{L}` matches / len) | 0.35 |
| average line length in `(12, 200)` | 0.10 |

Consumers use `0.4` as the "text layer is usable" gate (§3.2).
