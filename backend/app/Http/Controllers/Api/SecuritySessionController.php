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
            ->with(['device', 'membership.user'])
            ->where('tenant_id', $tenantContext->tenantId())
            ->orderByDesc('last_seen_at');

        if (! app(\App\Authz\PermissionService::class)->can('security.sessions.revoke')) {
            $query->where('user_id', $tenantContext->userId());
        }

        return AuthSessionRecordResource::collection($query->get());
    }

    public function locationHistory(Request $request, TenantContext $tenantContext): JsonResponse
    {
        if (! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'Only the tenant owner may view staff locations.', 403);
        }
        $this->authorize('viewAny', AuthSessionRecord::class);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'username' => ['sometimes', 'string', 'max:120'],
        ]);
        $page = \Illuminate\Support\Facades\DB::table('login_location_observations as observation')
            ->join('memberships as member', function ($join) {
                $join->on('member.id', '=', 'observation.membership_id')
                    ->on('member.tenant_id', '=', 'observation.tenant_id');
            })
            ->leftJoin('devices as device', function ($join) {
                $join->on('device.id', '=', 'observation.device_id')
                    ->on('device.tenant_id', '=', 'observation.tenant_id');
            })
            ->where('observation.tenant_id', $tenantContext->tenantId())
            ->where('observation.expires_at', '>', now())
            ->when(isset($data['username']), fn ($query) => $query->where('member.username', $data['username']))
            ->orderByDesc('observation.observed_at')->orderByDesc('observation.id')
            ->paginate(50, [
                'observation.ulid', 'observation.latitude_approx', 'observation.longitude_approx',
                'observation.accuracy_meters', 'observation.observed_at', 'observation.expires_at',
                'member.ulid as membership_ulid', 'member.username',
                'device.ulid as device_ulid', 'device.name as device_name',
            ], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'locations' => $page->items(),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'precision' => 'approximate',
            'retention_days' => 30,
        ]);
    }

    public function loginHistory(Request $request, TenantContext $tenantContext): JsonResponse
    {
        if (! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'Only the tenant owner may view staff login history.', 403);
        }
        $this->authorize('viewAny', AuthSessionRecord::class);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'username' => ['sometimes', 'string', 'max:120'],
            'ip_address' => ['sometimes', 'ip'],
            'event' => ['sometimes', 'in:all,LOGIN_SUCCESS,LOGIN_FAILURE,LOGIN_BLOCKED'],
        ]);

        $page = \Illuminate\Support\Facades\DB::table('audit_logs as audit')
            ->leftJoin('memberships as member', function ($join) {
                $join->on('member.id', '=', 'audit.actor_membership_id')
                    ->on('member.tenant_id', '=', 'audit.tenant_id');
            })
            ->leftJoin('devices as device', function ($join) {
                $join->on('device.id', '=', 'audit.device_id')
                    ->on('device.tenant_id', '=', 'audit.tenant_id');
            })
            ->where('audit.tenant_id', $tenantContext->tenantId())
            ->whereIn('audit.event', ['LOGIN_SUCCESS', 'LOGIN_FAILURE', 'LOGIN_BLOCKED'])
            ->when(isset($filters['username']), fn ($q) => $q->where('member.username', $filters['username']))
            ->when(isset($filters['ip_address']), fn ($q) => $q->where('audit.ip_address', $filters['ip_address']))
            ->when(isset($filters['event']) && $filters['event'] !== 'all', fn ($q) => $q->where('audit.event', $filters['event']))
            ->orderByDesc('audit.occurred_at')->orderByDesc('audit.id')
            ->paginate(50, [
                'audit.ulid', 'audit.event', 'audit.ip_address', 'audit.user_agent', 'audit.occurred_at',
                'member.ulid as membership_ulid', 'member.username',
                'device.ulid as device_ulid', 'device.name as device_name',
            ], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'events' => $page->items(),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
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
