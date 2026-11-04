<?php

namespace App\Platform\Imports;

use App\Exceptions\ApiException;
use App\Support\SimpleXlsx;
use Symfony\Component\Process\Process;

final class WorkbookParser
{
    public function __construct(private readonly SimpleXlsx $xlsx) {}

    public function parse(string $path, string $extension = 'xlsx'): array
    {
        $extension = strtolower($extension);
        if ($extension === 'pdf') {
            return $this->parsePdf($path);
        }
        $matrix = $extension === 'csv' ? $this->csvMatrix($path) : $this->xlsx->readMatrix($path);
        $header = $matrix[1]['cells'] ?? [];
        if (($header['A'] ?? '') === 'Main Head' && ($header['B'] ?? '') === 'Account'
            && ($header['C'] ?? '') === 'Head' && ($header['D'] ?? '') === 'Sub Head') {
            return $this->hierarchicalAccounts($matrix);
        }
        if (($header['A'] ?? '') === 'ID' && ($header['C'] ?? '') === 'CODE'
            && ($header['D'] ?? '') === 'Description'
            && ($header['H'] ?? '') === 'Cost Price' && ($header['J'] ?? '') === 'Selling') {
            $rows = [];
            foreach ($matrix as $number => $entry) {
                if ($number === 1) continue;
                $c = $entry['cells'];
                if (($c['C'] ?? '') === '' && ($c['D'] ?? '') === '') continue;
                $rows[] = [
                    'row' => $number, 'legacy_id' => $c['A'] ?? '',
                    'code' => $c['C'] ?? '', 'name' => $c['D'] ?? '',
                    'category' => $c['F'] ?? '', 'brand' => $c['E'] ?? '',
                    'unit' => $c['G'] ?? '', 'stock' => $c['K'] ?? '0',
                    'retail' => $c['J'] ?? '', 'cost' => $c['H'] ?? '',
                    'formula' => $entry['formula'],
                ];
            }
            return $this->result('products', $rows) + ['ignored_fields' => ['Disc and Margin are not authoritative pricing fields; skipped.']];
        }
        $first = $matrix[1]['cells'] ?? [];
        if ($first === ['A' => 'ID', 'B' => 'CODE', 'C' => 'Vendor / Customer / Accounts', 'D' => 'Contacts', 'E' => 'Account Type', 'F' => 'TYPE']) {
            $rows = [];
            foreach ($matrix as $number => $entry) {
                if ($number === 1 || implode('', $entry['cells']) === '') {
                    continue;
                }
                $c = $entry['cells'];
                $rows[] = ['row' => $number, 'legacy_id' => $c['A'] ?? '', 'code' => $c['B'] ?? '',
                    'name' => $c['C'] ?? '', 'contact' => $c['D'] ?? '', 'account_type' => $c['E'] ?? '',
                    'type' => strtoupper($c['F'] ?? ''), 'formula' => $entry['formula']];
            }

            return $this->result('accounts', $rows);
        }
        $headers = ['A' => 'ID', 'B' => 'CODE', 'G' => 'Description of Item', 'H' => 'In Carton', 'I' => 'In Stock', 'K' => 'Retail', 'L' => 'Pur.Rate'];
        $found = false;
        $category = '';
        $rows = [];
        foreach ($matrix as $number => $entry) {
            $c = $entry['cells'];
            if (array_intersect_assoc($c, $headers) === $headers) {
                $found = true;

                continue;
            }
            if (isset($c['A']) && $c['A'] !== '' && ! is_numeric($c['A']) && ($c['G'] ?? '') === '' && $number >= 4) {
                $category = $c['A'];

                continue;
            }
            if ($found && preg_match('/^\d+$/', $c['A'] ?? '')) {
                $rows[] = ['row' => $number, 'legacy_id' => $c['A'], 'code' => $c['B'] ?? '',
                    'name' => $c['G'] ?? '', 'category' => $category, 'stock' => $c['I'] ?? '',
                    'retail' => $c['K'] ?? '', 'cost' => $c['L'] ?? '', 'formula' => $entry['formula']];
            }
        }
        if (! $found || $rows === []) {
            throw new ApiException('VALIDATION_FAILED', 'Unsupported XLSX format. Upload the accounts or Sales + Purchase Price workbook.', 422);
        }

        return $this->result('products', $rows);
    }


    /** CSV has the same source layout as the Account List export; stream with limits. */
    private function csvMatrix(string $path): array
    {
        $stream = fopen($path, 'rb');
        if (! $stream) throw new ApiException('VALIDATION_FAILED', 'Cannot read CSV file.', 422);
        try {
            $matrix = [];
            while (($fields = fgetcsv($stream, 65536, ',', '"', '\\')) !== false) {
                $number = count($matrix) + 1;
                if ($number > 10000 || count($fields) > 40) {
                    throw new ApiException('VALIDATION_FAILED', 'CSV exceeds the supported row or column limit.', 422);
                }
                $cells = [];
                foreach ($fields as $index => $value) {
                    if (strlen((string) $value) > 2000) throw new ApiException('VALIDATION_FAILED', 'CSV cell exceeds text limit.', 422);
                    $cells[chr(65 + $index)] = trim($number === 1 && $index === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $value) : (string) $value);
                }
                $matrix[$number] = ['cells' => $cells, 'formula' => false];
            }
            return $matrix;
        } finally {
            fclose($stream);
        }
    }

    /** All three account exports share the same source identity + COA type code. */
    private function hierarchicalAccounts(array $matrix): array
    {
        $rows = [];
        foreach ($matrix as $number => $entry) {
            if ($number === 1) continue;
            $c = $entry['cells'];
            $account = trim((string) ($c['B'] ?? ''));
            $subHead = trim((string) ($c['D'] ?? ''));
            if (! preg_match('/^([0-9]+(?:-[0-9]+)?)-(.+)$/u', $account, $match)
                || ! preg_match('/^(\d{4})-/', $subHead, $typeCode)) continue;
            $name = trim($match[2]);
            if ($name === '') continue;
            $rows[] = [
                'row' => $number, 'legacy_id' => $match[1], 'code' => $match[1],
                'name' => $name, 'contact' => '', 'account_type' => $subHead,
                'account_type_code' => $typeCode[1],
                'type' => match ($typeCode[1]) {
                    '0011' => 'CUSTOMERS', '0020' => 'VENDORS', default => 'ACCOUNTS',
                },
                'formula' => $entry['formula'],
            ];
        }
        if (! $rows) throw new ApiException('VALIDATION_FAILED', 'No account rows found in the source file.', 422);
        return $this->result('accounts', $rows);
    }

    /**
     * PDF text extraction requires pdftotext on the server. Do not guess data
     * from page images or silently return a partial import.
     */
    private function parsePdf(string $path): array
    {
        $process = new Process(['pdftotext', '-layout', '-nopgbrk', $path, '-']);
        $process->setTimeout(30);
        try {
            $process->run();
        } catch (\Throwable) {
            throw new ApiException('VALIDATION_FAILED', 'PDF import needs the pdftotext utility installed on the server; upload the CSV or XLSX export instead.', 422);
        }
        if (! $process->isSuccessful() || strlen($process->getOutput()) > 3 * 1024 * 1024) {
            throw new ApiException('VALIDATION_FAILED', 'Cannot safely extract text from this PDF. Use CSV or XLSX export.', 422);
        }
        $matrix = [1 => ['cells' => ['A' => 'Main Head', 'B' => 'Account', 'C' => 'Head', 'D' => 'Sub Head'], 'formula' => false]];
        $number = 1;
        $currentMain = '';
        foreach (preg_split('/\R/u', $process->getOutput()) as $line) {
            if (preg_match('/^\s*Main Head:\s*(\d{2}-[^\r\n]+)/u', $line, $m)) {
                $currentMain = trim($m[1]);
            }
            // pdftotext -layout preserves the leading group code and trailing
            // numeric subhead code; text rows with ambiguous structure are skipped.
            if (! preg_match('/^\s*\d{2}-(?:L|I)?\s+(.+?)\s+(\d{3}-\S.*?)\s+(\d{4}-\S.*?)\s*$/u', $line, $m)) continue;
            $number++;
            if ($number > 10000) throw new ApiException('VALIDATION_FAILED', 'PDF exceeds row limit.', 422);
            $matrix[$number] = ['cells' => [
                'A' => $currentMain, 'B' => trim($m[1]), 'C' => trim($m[2]), 'D' => trim($m[3]),
            ], 'formula' => false];
        }
        return $this->hierarchicalAccounts($matrix);
    }

    private function result(string $format, array $rows): array
    {
        return ['format' => $format, 'rows' => $rows,
            'fingerprint' => hash('sha256', 'bluepos-legacy-v1:'.$format.':'.json_encode($rows, JSON_THROW_ON_ERROR))];
    }
}
