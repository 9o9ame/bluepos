<?php

namespace App\Services\CustomQuery;

use App\Exceptions\ApiException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SafeCustomQueryRunner
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return array<string, array{table:string, scope:string, columns:list<string>}>
     */
    public function catalog(): array
    {
        return [
            'products' => [
                'table' => 'products',
                'scope' => 'tenant',
                'columns' => [
                    'ulid', 'product_number', 'sku', 'name', 'alternate_name',
                    'tax_percent', 'is_taxable', 'track_batch', 'track_expiry',
                    'reorder_level', 'minimum_stock', 'maximum_stock',
                    'rack_location', 'status', 'is_active',
                ],
            ],
            'party_profiles' => [
                'table' => 'party_profiles',
                'scope' => 'tenant',
                'columns' => [
                    'ulid', 'code', 'name', 'contact_person', 'mobile', 'phone',
                    'email', 'area', 'is_active',
                ],
            ],
            'sales' => [
                'table' => 'sales',
                'scope' => 'branch_warehouse',
                'columns' => [
                    'ulid', 'document_number', 'status', 'sale_date', 'price_type',
                    'subtotal', 'discount_amount', 'tax_amount', 'grand_total', 'posted_at',
                ],
            ],
            'purchase_invoices' => [
                'table' => 'purchase_invoices',
                'scope' => 'branch_warehouse',
                'columns' => [
                    'ulid', 'document_number', 'supplier_invoice_number', 'invoice_type',
                    'currency_code', 'invoice_date', 'due_date', 'status', 'subtotal',
                    'discount_amount', 'tax_amount', 'grand_total', 'posted_at',
                ],
            ],
        ];
    }

    /**
     * @return array{dataset:string, columns:list<string>, rows:list<array<string,mixed>>, row_count:int, truncated:bool, limit:int}
     */
    public function run(string $input): array
    {
        $queryText = trim($input);

        if ($queryText === '' || strlen($queryText) > 5000) {
            throw $this->invalid('Query must be between 1 and 5000 characters.');
        }

        if (preg_match('/;|--|\/\*|\*\//', $queryText)) {
            throw $this->invalid('Multiple statements and SQL comments are not allowed.');
        }

        if (preg_match('/\b(insert|update|delete|alter|drop|truncate|create|grant|revoke|copy|call|execute|do|set|show|vacuum|analyze|refresh|merge)\b/i', $queryText)) {
            throw $this->invalid('Only read-only SELECT queries are allowed.');
        }

        $pattern = '/\ASELECT\s+(?<select>.+?)\s+FROM\s+(?<dataset>[a-z_][a-z0-9_]*)(?:\s+WHERE\s+(?<where>.*?))?(?:\s+ORDER\s+BY\s+(?<order>[a-z_][a-z0-9_]*)(?:\s+(?<direction>ASC|DESC))?)?(?:\s+LIMIT\s+(?<limit>\d+))?\s*\z/is';

        if (! preg_match($pattern, $queryText, $matches)) {
            throw $this->invalid(
                'Use SELECT columns FROM dataset with optional simple WHERE, ORDER BY, and LIMIT clauses.'
            );
        }

        $dataset = strtolower($matches['dataset']);
        $catalog = $this->catalog();
        $definition = $catalog[$dataset] ?? null;

        if (! $definition) {
            throw $this->invalid('This dataset is not available in Custom Query.');
        }

        $allowed = $definition['columns'];
        $selectRaw = trim($matches['select']);
        $countOnly = strcasecmp($selectRaw, 'count(*)') === 0;

        if ($countOnly) {
            $columns = ['count'];
        } elseif ($selectRaw === '*') {
            $columns = $allowed;
        } else {
            $columns = array_values(array_filter(array_map(
                static fn (string $column): string => strtolower(trim($column)),
                explode(',', $selectRaw),
            )));

            if ($columns === []) {
                throw $this->invalid('Select at least one column.');
            }

            foreach ($columns as $column) {
                $this->assertColumn($column, $allowed);
            }

            $columns = array_values(array_unique($columns));
        }

        $limit = isset($matches['limit']) && $matches['limit'] !== ''
            ? (int) $matches['limit']
            : 100;

        if ($limit < 1 || $limit > 200) {
            throw $this->invalid('LIMIT must be between 1 and 200.');
        }

        $order = isset($matches['order']) ? strtolower(trim($matches['order'])) : '';
        if ($order !== '') {
            $this->assertColumn($order, $allowed);
        }

        $direction = strtoupper((string) ($matches['direction'] ?? 'ASC'));
        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            throw $this->invalid('ORDER BY direction must be ASC or DESC.');
        }

        $whereText = trim((string) ($matches['where'] ?? ''));

        return DB::transaction(function () use (
            $definition,
            $dataset,
            $allowed,
            $columns,
            $countOnly,
            $limit,
            $order,
            $direction,
            $whereText,
        ): array {
            DB::statement('SET LOCAL TRANSACTION READ ONLY');
            DB::statement("SET LOCAL statement_timeout = '3000ms'");

            $builder = DB::table($definition['table'])
                ->where('tenant_id', $this->tenantContext->tenantId());

            if ($definition['scope'] === 'branch_warehouse') {
                $builder
                    ->where('branch_id', $this->tenantContext->branchId())
                    ->where('warehouse_id', $this->tenantContext->warehouseId());
            }

            if ($whereText !== '') {
                $this->applyWhere($builder, $whereText, $allowed);
            }

            if ($countOnly) {
                return [
                    'dataset' => $dataset,
                    'columns' => ['count'],
                    'rows' => [['count' => (string) $builder->count()]],
                    'row_count' => 1,
                    'truncated' => false,
                    'limit' => $limit,
                ];
            }

            if ($order !== '') {
                $builder->orderBy($order, $direction);
            } else {
                $builder->orderBy($allowed[0]);
            }

            $items = $builder
                ->select($columns)
                ->limit($limit + 1)
                ->get();

            $truncated = $items->count() > $limit;
            $rows = $items
                ->take($limit)
                ->map(static fn (object $row): array => (array) $row)
                ->values()
                ->all();

            return [
                'dataset' => $dataset,
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => count($rows),
                'truncated' => $truncated,
                'limit' => $limit,
            ];
        });
    }

    /**
     * @param list<string> $allowed
     */
    private function applyWhere(Builder $builder, string $whereText, array $allowed): void
    {
        $conditions = preg_split('/\s+AND\s+/i', trim($whereText)) ?: [];

        if ($conditions === [] || count($conditions) > 8) {
            throw $this->invalid('WHERE supports between 1 and 8 conditions joined by AND.');
        }

        foreach ($conditions as $condition) {
            $pattern = "/\A([a-z_][a-z0-9_]*)\s*(=|!=|<>|>=|<=|>|<|ILIKE|LIKE)\s*(NULL|TRUE|FALSE|-?\d+(?:\.\d+)?|'(?:''|[^'])*')\z/i";

            if (! preg_match($pattern, trim($condition), $match)) {
                throw $this->invalid(
                    'WHERE supports column comparisons joined by AND. String values must use single quotes.'
                );
            }

            $column = strtolower($match[1]);
            $operator = strtoupper($match[2]);
            $rawValue = $match[3];

            $this->assertColumn($column, $allowed);

            if (strcasecmp($rawValue, 'NULL') === 0) {
                if ($operator === '=') {
                    $builder->whereNull($column);
                    continue;
                }
                if (in_array($operator, ['!=', '<>'], true)) {
                    $builder->whereNotNull($column);
                    continue;
                }

                throw $this->invalid('NULL only supports = or != comparisons.');
            }

            if (strcasecmp($rawValue, 'TRUE') === 0 || strcasecmp($rawValue, 'FALSE') === 0) {
                $value = strcasecmp($rawValue, 'TRUE') === 0;
            } elseif (str_starts_with($rawValue, "'")) {
                $value = str_replace("''", "'", substr($rawValue, 1, -1));
            } else {
                $value = $rawValue;
            }

            if (in_array($operator, ['LIKE', 'ILIKE'], true) && ! is_string($value)) {
                throw $this->invalid('LIKE and ILIKE require a quoted string value.');
            }

            $builder->where($column, $operator === '<>' ? '!=' : strtolower($operator), $value);
        }
    }

    /**
     * @param list<string> $allowed
     */
    private function assertColumn(string $column, array $allowed): void
    {
        if (! in_array($column, $allowed, true)) {
            throw $this->invalid("Column {$column} is not available in this dataset.");
        }
    }

    private function invalid(string $message): ApiException
    {
        return new ApiException('CUSTOM_QUERY_INVALID', $message, 422);
    }
}
