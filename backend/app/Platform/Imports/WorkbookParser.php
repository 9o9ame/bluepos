<?php

namespace App\Platform\Imports;

use App\Exceptions\ApiException;
use App\Support\SimpleXlsx;

final class WorkbookParser
{
    public function __construct(private readonly SimpleXlsx $xlsx) {}

    public function parse(string $path): array
    {
        $matrix = $this->xlsx->readMatrix($path);
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

    private function result(string $format, array $rows): array
    {
        return ['format' => $format, 'rows' => $rows,
            'fingerprint' => hash('sha256', 'bluepos-legacy-v1:'.$format.':'.json_encode($rows, JSON_THROW_ON_ERROR))];
    }
}
