<?php

namespace App\Domain\Signals\Ingestion;

/**
 * Turns an uploaded document into referenced text segments: [{ref, text}].
 *
 * This is plain extraction. It never calls an AI provider - ingesting a file is a
 * separate act from analysing it. Every segment carries a reference a reader can
 * follow back to the original ("Page 3", "Sheet1 row 12", "Section 2"), and those
 * references are what AI findings must cite.
 *
 * Supported: .txt, .pdf, .docx, .xlsx. Legacy binary .xls is refused with an
 * instruction to re-save as .xlsx: it needs a full BIFF parser, and a half-working
 * one would silently drop data.
 */
class DocumentExtractor
{
    public const EXTENSIONS = ['txt', 'pdf', 'docx', 'xlsx', 'xls'];

    /**
     * @return array{segments: array<int, array{ref: string, text: string}>, truncated: bool}
     *
     * @throws \RuntimeException with a user-safe message
     */
    public function extract(string $bytes, string $extension): array
    {
        $segments = match (strtolower($extension)) {
            'txt' => $this->text($bytes),
            'pdf' => $this->pdf($bytes),
            'docx' => $this->docx($bytes),
            'xlsx' => $this->xlsx($bytes),
            'xls' => throw new \RuntimeException('Legacy .xls files are not supported. Please save the workbook as .xlsx and upload it again.'),
            default => throw new \RuntimeException('This file type is not supported.'),
        };

        return $this->capped($segments);
    }

    /** @return array<int, array{ref: string, text: string}> */
    public static function chunk(string $text, string $prefix = 'Section', int $size = 3000): array
    {
        $text = trim(preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $text)) ?? '');
        if ($text === '') {
            return [];
        }

        $segments = [];
        $buffer = '';
        foreach (preg_split("/\n{1,}/", $text) as $paragraph) {
            if ($buffer !== '' && strlen($buffer) + strlen($paragraph) > $size) {
                $segments[] = ['ref' => "{$prefix} " . (count($segments) + 1), 'text' => trim($buffer)];
                $buffer = '';
            }
            // A single very long paragraph is split hard so no segment is unbounded.
            while (strlen($paragraph) > $size) {
                $segments[] = ['ref' => "{$prefix} " . (count($segments) + 1), 'text' => mb_strcut($paragraph, 0, $size)];
                $paragraph = mb_strcut($paragraph, $size);
            }
            $buffer .= ($buffer === '' ? '' : "\n") . $paragraph;
        }
        if (trim($buffer) !== '') {
            $segments[] = ['ref' => "{$prefix} " . (count($segments) + 1), 'text' => trim($buffer)];
        }

        return $segments;
    }

    private function text(string $bytes): array
    {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }

        return self::chunk($bytes, 'Section');
    }

    private function pdf(string $bytes): array
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('PDF reading is not available on this server.');
        }

        try {
            $document = (new \Smalot\PdfParser\Parser())->parseContent($bytes);
            $segments = [];
            foreach ($document->getPages() as $i => $page) {
                $text = trim(preg_replace('/[ \t]+/', ' ', $page->getText()) ?? '');
                if ($text !== '') {
                    $segments[] = ['ref' => 'Page ' . ($i + 1), 'text' => $text];
                }
            }
        } catch (\Throwable) {
            throw new \RuntimeException('The PDF could not be read. It may be encrypted or damaged.');
        }

        if ($segments === []) {
            throw new \RuntimeException('No text could be extracted from this PDF (it may be a scanned image).');
        }

        return $segments;
    }

    private function docx(string $bytes): array
    {
        $zip = $this->zip($bytes);
        $xml = $zip->read('word/document.xml');
        if ($xml === null) {
            throw new \RuntimeException('This does not look like a Word document.');
        }

        $paragraphs = [];
        preg_match_all('#<w:p[ >].*?</w:p>#s', $xml, $blocks);
        foreach ($blocks[0] as $block) {
            preg_match_all('#<w:t(?: [^>]*)?>(.*?)</w:t>|<w:tab/>#s', $block, $m, PREG_SET_ORDER);
            $line = '';
            foreach ($m as $part) {
                $line .= isset($part[1]) ? html_entity_decode($part[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : "\t";
            }
            if (trim($line) !== '') {
                $paragraphs[] = trim($line);
            }
        }

        if ($paragraphs === []) {
            throw new \RuntimeException('No text could be extracted from this document.');
        }

        return self::chunk(implode("\n", $paragraphs), 'Section');
    }

    private function xlsx(string $bytes): array
    {
        $zip = $this->zip($bytes);
        $workbook = $zip->read('xl/workbook.xml');
        if ($workbook === null) {
            throw new \RuntimeException('This does not look like an Excel workbook.');
        }

        $strings = [];
        if (($shared = $zip->read('xl/sharedStrings.xml')) !== null) {
            preg_match_all('#<si>(.*?)</si>#s', $shared, $items);
            foreach ($items[1] as $item) {
                preg_match_all('#<t(?: [^>]*)?>(.*?)</t>#s', $item, $t);
                $strings[] = html_entity_decode(implode('', $t[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        $rels = [];
        if (($relXml = $zip->read('xl/_rels/workbook.xml.rels')) !== null) {
            preg_match_all('#<Relationship [^>]*>#', $relXml, $tags);
            foreach ($tags[0] as $tag) {
                if (preg_match('#Id="([^"]+)"#', $tag, $id) && preg_match('#Target="([^"]+)"#', $tag, $target)) {
                    $rels[$id[1]] = ltrim(str_starts_with($target[1], '/') ? substr($target[1], 1) : 'xl/' . $target[1], '/');
                }
            }
        }

        $segments = [];
        preg_match_all('#<sheet [^>]*>#', $workbook, $sheets);
        foreach ($sheets[0] as $n => $tag) {
            $name = preg_match('#name="([^"]*)"#', $tag, $nm) ? html_entity_decode($nm[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : 'Sheet' . ($n + 1);
            $rid = preg_match('#r:id="([^"]+)"#', $tag, $r) ? $r[1] : null;
            $path = $rels[$rid] ?? 'xl/worksheets/sheet' . ($n + 1) . '.xml';
            $sheet = $zip->read($path);
            if ($sheet === null) {
                continue;
            }

            preg_match_all('#<row [^>]*?r="(\d+)"[^>]*>(.*?)</row>#s', $sheet, $rows, PREG_SET_ORDER);
            foreach ($rows as $row) {
                $cells = [];
                preg_match_all('#<c ([^>]*)>(.*?)</c>#s', $row[2], $cs, PREG_SET_ORDER);
                foreach ($cs as $c) {
                    $ref = preg_match('#r="([A-Z]+\d+)"#', $c[1], $rr) ? $rr[1] : '';
                    $type = preg_match('#t="([^"]+)"#', $c[1], $tt) ? $tt[1] : '';
                    $value = '';
                    if ($type === 'inlineStr') {
                        preg_match_all('#<t(?: [^>]*)?>(.*?)</t>#s', $c[2], $t);
                        $value = html_entity_decode(implode('', $t[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    } elseif (preg_match('#<v>(.*?)</v>#s', $c[2], $v)) {
                        $value = $type === 's' ? ($strings[(int) $v[1]] ?? '') : html_entity_decode($v[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                    if (trim($value) !== '') {
                        $cells[] = "{$ref}: " . trim($value);
                    }
                }
                if ($cells !== []) {
                    $segments[] = ['ref' => "{$name} row {$row[1]}", 'text' => implode(' | ', $cells)];
                }
            }
        }

        if ($segments === []) {
            throw new \RuntimeException('No data could be extracted from this workbook.');
        }

        return $segments;
    }

    private function zip(string $bytes): ZipReader
    {
        try {
            return new ZipReader($bytes);
        } catch (\Throwable) {
            throw new \RuntimeException('The file is damaged or is not a valid Office document.');
        }
    }

    /**
     * @param  array<int, array{ref: string, text: string}>  $segments
     * @return array{segments: array<int, array{ref: string, text: string}>, truncated: bool}
     */
    private function capped(array $segments): array
    {
        $limit = (int) config('signals.ingestion.max_chars', 300000);
        $total = 0;
        $out = [];
        foreach ($segments as $segment) {
            if ($total + strlen($segment['text']) > $limit) {
                return ['segments' => $out, 'truncated' => true];
            }
            $total += strlen($segment['text']);
            $out[] = $segment;
        }

        return ['segments' => $out, 'truncated' => false];
    }
}
