<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Warehouse;
use App\Security\AuditLogger;
use App\Security\DeviceCredentialService;
use App\Security\SessionRevocationService;
use App\Security\TenantEntitlementService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceCredentialService $credentials) {}

    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $existing = $this->credentials->locate($request);
        if ($existing) {
            return (new DeviceResource($existing))->response()->cookie($this->credentials->cookie($this->credentials->read($request) ?? ''));
        }

        $device = Device::query()->create([
            'name' => $data['name'] ?? 'POS terminal',
            'device_type' => 'pos_terminal',
            'status' => DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $credential = $this->credentials->issue($device);

        app(AuditLogger::class)->record('DEVICE_ENROLLMENT_REQUESTED', [
            'resource_type' => 'device',
            'resource_ulid' => $device->ulid,
        ], $request);

        return (new DeviceResource($device))
            ->response()
            ->cookie($this->credentials->cookie($credential));
    }

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Device::class);

        return DeviceResource::collection(
            Device::query()
                ->forTenant($tenantContext->tenantId())
                ->with('branch', 'warehouse', 'approver')
                ->orderByDesc('registered_at')
                ->get()
        );
    }

    public function approve(Request $request, string $deviceUlid, TenantContext $tenantContext): DeviceResource
    {
        $device = $this->findTenantDevice($tenantContext, $deviceUlid);
        $this->authorize('approve', $device);

        if ($tenantContext->membership()->hasRoleCode(\App\Authz\PermissionCatalogue::CASHIER)
            && ! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        $data = $request->validate([
            'branch_ulid' => ['nullable', 'string', 'size:26'],
            'warehouse_ulid' => ['nullable', 'string', 'size:26'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        if ($device->status !== DeviceStatus::Active) {
            app(TenantEntitlementService::class)->assertCanRegisterDevice($tenantContext->tenant());
        }

        $device->status = DeviceStatus::Active;
        $device->approved_at = now();
        $device->approved_by = $tenantContext->userId();
        $device->tenant_id = $tenantContext->tenantId();
        if (isset($data['name'])) {
            $device->name = $data['name'];
        }
        if (! empty($data['branch_ulid'])) {
            $branch = Branch::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['branch_ulid'])->firstOrFail();
            $device->branch_id = $branch->id;
        }
        if (! empty($data['warehouse_ulid'])) {
            $warehouse = Warehouse::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['warehouse_ulid'])->firstOrFail();
            $device->warehouse_id = $warehouse->id;
        }
        $device->save();

        app(AuditLogger::class)->record('DEVICE_APPROVED', [
            'resource_type' => 'device',
            'resource_ulid' => $device->ulid,
        ], $request);

        return new DeviceResource($device->fresh(['branch', 'warehouse', 'approver']));
    }

    public function revoke(Request $request, string $deviceUlid, TenantContext $tenantContext, SessionRevocationService $revocation): DeviceResource
    {
        $device = $this->findTenantDevice($tenantContext, $deviceUlid);
        $this->authorize('revoke', $device);
        $revocation->revokeDevice($device);

        app(AuditLogger::class)->record('DEVICE_REVOKED', [
            'resource_type' => 'device',
            'resource_ulid' => $device->ulid,
        ], $request);

        return new DeviceResource($device->fresh(['branch', 'warehouse', 'approver']));
    }

    public function assign(Request $request, string $deviceUlid, TenantContext $tenantContext): DeviceResource
    {
        $device = $this->findTenantDevice($tenantContext, $deviceUlid);
        $this->authorize('assignBranch', $device);
        $data = $request->validate([
            'branch_ulid' => ['nullable', 'string', 'size:26'],
            'warehouse_ulid' => ['nullable', 'string', 'size:26'],
        ]);
        if (! empty($data['branch_ulid'])) {
            $device->branch_id = Branch::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['branch_ulid'])->firstOrFail()->id;
        } else {
            $device->branch_id = null;
        }
        if (! empty($data['warehouse_ulid'])) {
            $device->warehouse_id = Warehouse::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['warehouse_ulid'])->firstOrFail()->id;
        } else {
            $device->warehouse_id = null;
        }
        $device->save();

        return new DeviceResource($device->fresh(['branch', 'warehouse', 'approver']));
    }

    private function findTenantDevice(TenantContext $tenantContext, string $deviceUlid): Device
    {
        $device = Device::query()
            ->where('ulid', $deviceUlid)
            ->where(function ($query) use ($tenantContext): void {
                $query->where('tenant_id', $tenantContext->tenantId())
                    ->orWhereNull('tenant_id');
            })
            ->first();

        if (! $device) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $device;
    }
}
