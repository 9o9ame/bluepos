<?php

namespace App\Support;

use App\Exceptions\ApiException;
use ZipArchive;

/**
 * Minimal XLSX reader/writer (no formula execution).
 */
class SimpleXlsx
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|null>>  $rows
     */
    public function write(array $headers, array $rows = []): string
    {
        $sheetRows = array_merge([$headers], $rows);
        $sheetXml = $this->sheetXml($sheetRows);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new ApiException('SERVER_ERROR', 'Unable to create spreadsheet temp file.', 500);
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new ApiException('SERVER_ERROR', 'Unable to create spreadsheet archive.', 500);
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Parties" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml($sheetRows));
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $binary = file_get_contents($tmp);
        @unlink($tmp);
        if ($binary === false) {
            throw new ApiException('SERVER_ERROR', 'Unable to read spreadsheet temp file.', 500);
        }

        return $binary;
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>, formula_rows: list<int>}
     */
    public function read(string $absolutePath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new ApiException('VALIDATION_FAILED', 'Unable to open spreadsheet file.', 422);
        }

        $shared = $this->parseSharedStrings((string) $zip->getFromName('xl/sharedStrings.xml'));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheet === '') {
            throw new ApiException('VALIDATION_FAILED', 'Spreadsheet sheet is missing.', 422);
        }

        return $this->parseSheet($sheet, $shared);
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function sharedStringsXml(array $rows): string
    {
        $unique = [];
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $value = (string) ($cell ?? '');
                $unique[$value] = true;
            }
        }
        $items = array_keys($unique);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($items).'" uniqueCount="'.count($items).'">';
        foreach ($items as $item) {
            $xml .= '<si><t>'.htmlspecialchars($item, ENT_XML1).'</t></si>';
        }

        return $xml.'</sst>';
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function sheetXml(array $rows): string
    {
        $index = [];
        $n = 0;
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $value = (string) ($cell ?? '');
                if (! array_key_exists($value, $index)) {
                    $index[$value] = $n++;
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $row) {
            $rowNum = $r + 1;
            $xml .= '<row r="'.$rowNum.'">';
            foreach ($row as $c => $cell) {
                $ref = $this->colLetter($c).$rowNum;
                $si = $index[(string) ($cell ?? '')];
                $xml .= '<c r="'.$ref.'" t="s"><v>'.$si.'</v></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * @return list<string>
     */
    private function parseSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }
        $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out = [];
        foreach ($doc->xpath('//m:si') ?: [] as $si) {
            $si->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $texts = $si->xpath('.//m:t') ?: [];
            $value = '';
            foreach ($texts as $t) {
                $value .= (string) $t;
            }
            $out[] = $value;
        }

        return $out;
    }

    /**
     * @param  list<string>  $shared
     * @return array{headers: list<string>, rows: list<array<string, string>>, formula_rows: list<int>}
     */
    private function parseSheet(string $xml, array $shared): array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            throw new ApiException('VALIDATION_FAILED', 'Invalid spreadsheet XML.', 422);
        }
        $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $matrix = [];
        $formulaRows = [];
        foreach ($doc->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $rowIndex = ((int) $row['r']) - 1;
            foreach ($row->c ?? [] as $cell) {
                $ref = (string) $cell['r'];
                if (preg_match('/^([A-Z]+)(\d+)$/', $ref, $m) !== 1) {
                    continue;
                }
                $col = $this->colIndex($m[1]);
                if (isset($cell->f)) {
                    $formulaRows[$rowIndex] = true;
                }
                $type = (string) ($cell['t'] ?? '');
                $raw = isset($cell->v) ? (string) $cell->v : '';
                $value = $type === 's' ? ($shared[(int) $raw] ?? '') : $raw;
                $matrix[$rowIndex][$col] = trim($value);
            }
        }

        if ($matrix === []) {
            return ['headers' => [], 'rows' => [], 'formula_rows' => []];
        }

        ksort($matrix);
        $headerRow = $matrix[0] ?? [];
        ksort($headerRow);
        $headers = array_values($headerRow);
        $rows = [];
        foreach ($matrix as $idx => $cols) {
            if ($idx === 0) {
                continue;
            }
            ksort($cols);
            $assoc = [];
            foreach ($headers as $hIndex => $header) {
                $assoc[$header] = (string) ($cols[$hIndex] ?? '');
            }
            $rows[] = $assoc;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'formula_rows' => array_map(fn ($i) => $i + 1, array_keys($formulaRows)),
        ];
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        $n = $index;
        do {
            $letter = chr(65 + ($n % 26)).$letter;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $letter;
    }

    private function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = ($n * 26) + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
