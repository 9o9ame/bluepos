<?php

namespace App\Http\Controllers\Api;

use App\Actions\Memberships\ActivateMembershipAction;
use App\Actions\Memberships\CreateMembershipAction;
use App\Actions\Memberships\DeactivateMembershipAction;
use App\Actions\Memberships\ForceLogoutMembershipAction;
use App\Actions\Memberships\ResetMembershipPasswordAction;
use App\Actions\Memberships\SyncMembershipBranchesAction;
use App\Actions\Memberships\SyncMembershipRolesAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Memberships\StoreMembershipRequest;
use App\Http\Requests\Memberships\SyncMembershipBranchesRequest;
use App\Http\Requests\Memberships\SyncMembershipRolesRequest;
use App\Http\Requests\Memberships\UpdateMembershipSalesmanRequest;
use App\Http\Requests\Memberships\UpdateMembershipFingerprintRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Models\PartyProfile;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Membership::class);

        $memberships = Membership::query()
            ->where('tenant_id', $tenantContext->tenantId())
            ->with(['user', 'roles', 'branches', 'salesmanProfile'])
            ->orderByDesc('is_owner')
            ->orderBy('id')
            ->get();

        return MembershipResource::collection($memberships);
    }

    public function store(
        StoreMembershipRequest $request,
        CreateMembershipAction $createMembership,
    ): JsonResponse {
        $this->authorize('create', Membership::class);

        $data = $request->validated();
        $membership = $createMembership->execute([
            'name' => $data['name'],
            'username' => $data['username'],
            'recovery_email' => $data['recovery_email'] ?? null,
            'recovery_phone' => $data['recovery_phone'] ?? null,
            'password' => $data['password'],
            'must_change_password' => $data['must_change_password'] ?? true,
            'role_ulids' => $data['roles'],
            'branch_ulids' => $data['branches'] ?? [],
        ]);

        return (new MembershipResource($membership))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $membershipUlid, TenantContext $tenantContext): MembershipResource
    {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('view', $membership);

        return new MembershipResource($membership->load(['user', 'roles', 'branches', 'salesmanProfile']));
    }

    public function deactivate(
        string $membershipUlid,
        TenantContext $tenantContext,
        DeactivateMembershipAction $deactivateMembership,
    ): MembershipResource {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('deactivate', $membership);

        return new MembershipResource($deactivateMembership->execute($membership));
    }

    public function activate(
        string $membershipUlid,
        TenantContext $tenantContext,
        ActivateMembershipAction $activateMembership,
    ): MembershipResource {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('activate', $membership);

        return new MembershipResource($activateMembership->execute($membership));
    }

    public function resetPassword(
        Request $request,
        string $membershipUlid,
        TenantContext $tenantContext,
        ResetMembershipPasswordAction $resetPassword,
    ): JsonResponse {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('resetPassword', $membership);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $resetPassword->execute($membership, $data['password']);

        return response()->json(['ok' => true]);
    }

    public function forceLogout(
        string $membershipUlid,
        TenantContext $tenantContext,
        ForceLogoutMembershipAction $forceLogout,
    ): JsonResponse {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('forceLogout', $membership);

        $forceLogout->execute($membership);

        return response()->json(['ok' => true]);
    }

    public function syncRoles(
        SyncMembershipRolesRequest $request,
        string $membershipUlid,
        TenantContext $tenantContext,
        SyncMembershipRolesAction $syncMembershipRoles,
    ): MembershipResource {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('manageRoles', $membership);

        return new MembershipResource($syncMembershipRoles->execute(
            $membership,
            $request->validated('roles'),
        ));
    }

    public function syncBranches(
        SyncMembershipBranchesRequest $request,
        string $membershipUlid,
        TenantContext $tenantContext,
        SyncMembershipBranchesAction $syncMembershipBranches,
    ): MembershipResource {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('manageBranches', $membership);

        return new MembershipResource($syncMembershipBranches->execute(
            $membership,
            $request->validated('branches'),
        ));
    }

    /**
     * @return array<int, array{ulid:string, code:string, name:string}>
     */
    public function salesmen(TenantContext $tenantContext): array
    {
        $this->authorize('viewAny', Membership::class);

        return PartyProfile::query()
            ->forTenant($tenantContext->tenantId())
            ->where('is_active', true)
            ->whereHas('types', fn ($q) => $q->where('type', 'salesman'))
            ->orderBy('name')
            ->get()
            ->map(fn (PartyProfile $profile): array => [
                'ulid' => $profile->ulid,
                'code' => $profile->code,
                'name' => $profile->name,
            ])
            ->all();
    }

    public function updateSalesman(
        UpdateMembershipSalesmanRequest $request,
        string $membershipUlid,
        TenantContext $tenantContext,
    ): MembershipResource {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('update', $membership);

        $data = $request->validated();
        $salesmanId = null;

        if (! empty($data['salesman_ulid'])) {
            $salesman = PartyProfile::query()
                ->forTenant($tenantContext->tenantId())
                ->where('ulid', $data['salesman_ulid'])
                ->where('is_active', true)
                ->whereHas('types', fn ($q) => $q->where('type', 'salesman'))
                ->first();

            if (! $salesman) {
                throw new ApiException('VALIDATION_ERROR', 'The selected salesman is invalid.', 422, [
                    'salesman_ulid' => ['The selected salesman is invalid.'],
                ]);
            }

            $salesmanId = $salesman->id;
        }

        $membership->forceFill([
            'salesman_party_profile_id' => $salesmanId,
            'salesman_start_time' => $data['start_time'] ?? null,
            'salesman_end_time' => $data['end_time'] ?? null,
        ])->save();

        return new MembershipResource(
            $membership->refresh()->load(['user', 'roles', 'branches', 'salesmanProfile'])
        );
    }

    public function updateFingerprint(
        UpdateMembershipFingerprintRequest $request,
        string $membershipUlid,
        TenantContext $tenantContext,
    ): MembershipResource {
        $membership = $this->findTenantMembership($tenantContext, $membershipUlid);
        $this->authorize('update', $membership);

        $fingerprintRef = trim((string) ($request->validated('fingerprint_ref') ?? ''));
        $fingerprintRef = $fingerprintRef === '' ? null : $fingerprintRef;

        if ($fingerprintRef !== null) {
            $duplicate = Membership::query()
                ->where('tenant_id', $tenantContext->tenantId())
                ->where('fingerprint_ref', $fingerprintRef)
                ->where('id', '!=', $membership->id)
                ->exists();

            if ($duplicate) {
                throw new ApiException('VALIDATION_ERROR', 'This fingerprint reference is already assigned to another user.', 422, [
                    'fingerprint_ref' => ['This fingerprint reference is already assigned to another user.'],
                ]);
            }
        }

        try {
            $membership->forceFill(['fingerprint_ref' => $fingerprintRef])->save();
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23505') {
                throw new ApiException(
                    'VALIDATION_ERROR',
                    'This fingerprint reference is already assigned to another user.',
                    422,
                    ['fingerprint_ref' => ['This fingerprint reference is already assigned to another user.']],
                );
            }

            throw $exception;
        }

        return new MembershipResource(
            $membership->refresh()->load(['user', 'roles', 'branches', 'salesmanProfile'])
        );
    }

    private function findTenantMembership(TenantContext $tenantContext, string $membershipUlid): Membership
    {
        $membership = Membership::query()
            ->where('tenant_id', $tenantContext->tenantId())
            ->where('ulid', $membershipUlid)
            ->first();

        if (! $membership) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $membership;
    }
}
