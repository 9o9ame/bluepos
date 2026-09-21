<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\EstablishAuthSessionAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthSessionRecordResource;
use App\Models\AuthSessionRecord;
use App\Security\AuditLogger;
use App\Security\SessionRevocationService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecuritySessionController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', AuthSessionRecord::class);

        $query = AuthSessionRecord::query()
            ->with('device')
            ->where('tenant_id', $tenantContext->tenantId())
            ->orderByDesc('last_seen_at');

        if (! app(\App\Authz\PermissionService::class)->can('security.sessions.revoke')) {
            $query->where('user_id', $tenantContext->userId());
        }

        return AuthSessionRecordResource::collection($query->get());
    }

    public function destroy(string $sessionUlid, TenantContext $tenantContext, SessionRevocationService $revocation): JsonResponse
    {
        $record = AuthSessionRecord::query()
            ->where('tenant_id', $tenantContext->tenantId())
            ->where('ulid', $sessionUlid)
            ->first();

        if (! $record) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $this->authorize('revoke', $record);
        $revocation->revokeRecord($record);

        app(AuditLogger::class)->record('SESSION_REVOKED', [
            'resource_type' => 'auth_session',
            'resource_ulid' => $record->ulid,
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroyOthers(Request $request, TenantContext $tenantContext, SessionRevocationService $revocation): JsonResponse
    {
        $current = $request->session()->get(EstablishAuthSessionAction::AUTH_SESSION_ULID);

        AuthSessionRecord::query()
            ->where('tenant_id', $tenantContext->tenantId())
            ->where('user_id', $tenantContext->userId())
            ->whereNull('revoked_at')
            ->when(is_string($current), fn ($query) => $query->where('ulid', '!=', $current))
            ->get()
            ->each(function (AuthSessionRecord $record) use ($revocation): void {
                $revocation->revokeRecord($record);
            });

        app(AuditLogger::class)->record('SESSION_REVOKED', [
            'resource_type' => 'auth_session',
            'resource_ulid' => null,
            'scope' => 'others',
        ]);

        return response()->json(['ok' => true]);
    }
}
