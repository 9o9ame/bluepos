<?php

namespace App\Http\Controllers\Api;

use App\Authz\PermissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomQuery\RunCustomQueryRequest;
use App\Http\Requests\CustomQuery\SaveCustomQueryRequest;
use App\Models\CustomQuery;
use App\Security\AuditLogger;
use App\Services\CustomQuery\SafeCustomQueryRunner;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Throwable;

class CustomQueryController extends Controller
{
    public function catalog(
        PermissionService $permissions,
        SafeCustomQueryRunner $runner,
    ): array {
        abort_unless($permissions->can('database.query.read'), 403);

        return [
            'datasets' => collect($runner->catalog())
                ->map(fn (array $definition, string $name): array => [
                    'name' => $name,
                    'scope' => $definition['scope'],
                    'columns' => $definition['columns'],
                ])
                ->values()
                ->all(),
            'syntax' => 'SELECT columns FROM dataset [WHERE condition AND condition] [ORDER BY column ASC|DESC] [LIMIT 1..200]',
            'max_rows' => 200,
        ];
    }

    public function run(
        RunCustomQueryRequest $request,
        PermissionService $permissions,
        SafeCustomQueryRunner $runner,
        AuditLogger $audit,
    ): array {
        abort_unless($permissions->can('database.query.read'), 403);

        $queryText = (string) $request->validated('query');
        $started = microtime(true);

        try {
            $result = $runner->run($queryText);

            $audit->record('CUSTOM_QUERY_RUN', [
                'resource_type' => 'custom_query',
                'dataset' => $result['dataset'],
                'row_count' => $result['row_count'],
                'truncated' => $result['truncated'],
                'query_sha256' => hash('sha256', $queryText),
                'query_length' => strlen($queryText),
            ]);

            $result['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);

            return $result;
        } catch (Throwable $exception) {
            $audit->record('CUSTOM_QUERY_REJECTED', [
                'resource_type' => 'custom_query',
                'query_sha256' => hash('sha256', $queryText),
                'query_length' => strlen($queryText),
                'error_key' => method_exists($exception, 'getStatusCode')
                    ? (string) $exception->getStatusCode()
                    : 'error',
            ]);

            throw $exception;
        }
    }

    public function saved(
        TenantContext $tenantContext,
        PermissionService $permissions,
    ): array {
        abort_unless($permissions->can('database.query.read'), 403);

        return CustomQuery::query()
            ->forTenant($tenantContext->tenantId())
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomQuery $query): array => $this->savedPayload($query))
            ->all();
    }

    public function store(
        SaveCustomQueryRequest $request,
        TenantContext $tenantContext,
        PermissionService $permissions,
        SafeCustomQueryRunner $runner,
        AuditLogger $audit,
    ): JsonResponse {
        abort_unless($permissions->can('database.query.read'), 403);

        $data = $request->validated();

        // Validate the syntax and dataset without trusting the client to save arbitrary SQL.
        $runner->run((string) $data['query']);

        $query = CustomQuery::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            'title' => trim((string) $data['title']),
            'query_text' => trim((string) $data['query']),
            'created_by' => $tenantContext->userId(),
            'updated_by' => $tenantContext->userId(),
        ]);

        $audit->record('CUSTOM_QUERY_SAVED', [
            'resource_type' => 'custom_query',
            'resource_ulid' => $query->ulid,
            'query_sha256' => hash('sha256', $query->query_text),
            'query_length' => strlen($query->query_text),
        ]);

        return response()->json($this->savedPayload($query), 201);
    }

    public function update(
        SaveCustomQueryRequest $request,
        string $customQueryUlid,
        TenantContext $tenantContext,
        PermissionService $permissions,
        SafeCustomQueryRunner $runner,
        AuditLogger $audit,
    ): JsonResponse {
        abort_unless($permissions->can('database.query.read'), 403);

        $query = CustomQuery::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $customQueryUlid)
            ->firstOrFail();

        $data = $request->validated();

        // Revalidate saved SQL on every update so stored queries remain within the safe allowlist.
        $runner->run((string) $data['query']);

        $query->title = trim((string) $data['title']);
        $query->query_text = trim((string) $data['query']);
        $query->updated_by = $tenantContext->userId();
        $query->save();

        $audit->record('CUSTOM_QUERY_UPDATED', [
            'resource_type' => 'custom_query',
            'resource_ulid' => $query->ulid,
            'query_sha256' => hash('sha256', $query->query_text),
            'query_length' => strlen($query->query_text),
        ]);

        return response()->json($this->savedPayload($query->refresh()));
    }

    public function destroy(
        string $customQueryUlid,
        TenantContext $tenantContext,
        PermissionService $permissions,
        AuditLogger $audit,
    ): JsonResponse {
        abort_unless($permissions->can('database.query.read'), 403);

        $query = CustomQuery::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $customQueryUlid)
            ->firstOrFail();

        $resourceUlid = $query->ulid;
        $querySha256 = hash('sha256', $query->query_text);
        $queryLength = strlen($query->query_text);

        $query->delete();

        $audit->record('CUSTOM_QUERY_DELETED', [
            'resource_type' => 'custom_query',
            'resource_ulid' => $resourceUlid,
            'query_sha256' => $querySha256,
            'query_length' => $queryLength,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{ulid:string,title:string,query:string,updated_at:?string}
     */
    private function savedPayload(CustomQuery $query): array
    {
        return [
            'ulid' => $query->ulid,
            'title' => $query->title,
            'query' => $query->query_text,
            'updated_at' => $query->updated_at?->toIso8601String(),
        ];
    }
}
