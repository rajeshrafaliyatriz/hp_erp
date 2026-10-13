<?php

namespace Tests\Support;

/** Builds tiny real DOCX / XLSX / PDF files for tests, with no extensions or libraries. */
class OfficeFixtures
{
    /** @param array<string, string> $files name => content */
    public static function zip(array $files): string
    {
        $data = '';
        $central = '';
        foreach ($files as $name => $content) {
            $compressed = gzdeflate($content);
            $crc = crc32($content);
            $offset = strlen($data);
            $data .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($content), strlen($name), 0) . $name . $compressed;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }

        return $data . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }

    /** @param array<int, string> $paragraphs */
    public static function docx(array $paragraphs): string
    {
        $body = '';
        foreach ($paragraphs as $p) {
            $body .= '<w:p><w:r><w:t>' . htmlspecialchars($p, ENT_XML1) . '</w:t></w:r></w:p>';
        }

        return self::zip(['word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>']);
    }

    /** @param array<string, array<int, array<int, string>>> $sheets sheetName => rows => cells */
    public static function xlsx(array $sheets): string
    {
        $shared = [];
        $files = [];
        $sheetTags = '';
        $rels = '';
        $n = 0;
        foreach ($sheets as $name => $rows) {
            $n++;
            $xml = '<worksheet><sheetData>';
            foreach ($rows as $r => $cells) {
                $xml .= '<row r="' . ($r + 1) . '">';
                foreach ($cells as $c => $value) {
                    $shared[] = $value;
                    $xml .= '<c r="' . chr(65 + $c) . ($r + 1) . '" t="s"><v>' . (count($shared) - 1) . '</v></c>';
                }
                $xml .= '</row>';
            }
            $files["xl/worksheets/sheet{$n}.xml"] = $xml . '</sheetData></worksheet>';
            $sheetTags .= '<sheet name="' . htmlspecialchars($name, ENT_XML1) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $files['xl/workbook.xml'] = '<workbook xmlns:r="x"><sheets>' . $sheetTags . '</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<Relationships>' . $rels . '</Relationships>';
        $files['xl/sharedStrings.xml'] = '<sst>' . implode('', array_map(fn ($s) => '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>', $shared)) . '</sst>';

        return self::zip($files);
    }

    /** @param array<int, string> $pages one line of text per page */
    public static function pdf(array $pages): string
    {
        $objects = [];
        $kids = [];
        foreach ($pages as $i => $text) {
            $pageNo = 4 + $i * 2;
            $kids[] = "{$pageNo} 0 R";
            $stream = 'BT /F1 12 Tf 72 720 Td (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ') Tj ET';
            $objects[$pageNo] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents " . ($pageNo + 1) . " 0 R >>";
            $objects[$pageNo + 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "{$num} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref\n0 ' . (count($objects) + 1) . "\n0000000000 65535 f \n";
        $out = str_replace('xref\n', "xref\n", $out);
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
