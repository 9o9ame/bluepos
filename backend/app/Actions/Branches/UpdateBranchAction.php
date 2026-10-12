<?php

namespace App\Actions\Branches;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBranchAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array{
     *     code?: string,
     *     name?: string,
     *     status?: BranchStatus|string,
     *     is_default?: bool
     * }  $data
     */
    public function execute(Branch $branch, array $data): Branch
    {
        if ((int) $branch->tenant_id !== $this->tenantContext->tenantId()) {
            abort(404);
        }

        return DB::transaction(function () use ($branch, $data): Branch {
            $tenantBranches = Branch::query()
                ->forTenant($this->tenantContext->tenantId())
                ->lockForUpdate()
                ->get();

            $locked = $tenantBranches->firstWhere('id', $branch->id);
            if (! $locked) {
                abort(404);
            }

            $code = array_key_exists('code', $data)
                ? strtoupper(trim((string) $data['code']))
                : $locked->code;
            $name = array_key_exists('name', $data)
                ? trim((string) $data['name'])
                : $locked->name;
            $status = array_key_exists('status', $data)
                ? ($data['status'] instanceof BranchStatus
                    ? $data['status']
                    : BranchStatus::from((string) $data['status']))
                : $locked->status;
            $isDefault = array_key_exists('is_default', $data)
                ? (bool) $data['is_default']
                : (bool) $locked->is_default;

            if ($tenantBranches
                ->where('id', '!=', $locked->id)
                ->contains(fn (Branch $candidate) => strtoupper($candidate->code) === $code)) {
                throw ValidationException::withMessages([
                    'code' => 'This branch code is already in use.',
                ]);
            }

            if ($status === BranchStatus::Inactive && (int) $locked->id === $this->tenantContext->branchId()) {
                throw ValidationException::withMessages([
                    'status' => 'The active session branch cannot be deactivated.',
                ]);
            }

            if ($status === BranchStatus::Inactive && $isDefault) {
                throw ValidationException::withMessages([
                    'status' => 'The default branch must remain active.',
                ]);
            }

            if ($locked->is_default && ! $isDefault) {
                throw ValidationException::withMessages([
                    'is_default' => 'Set another branch as default instead of clearing the current default.',
                ]);
            }

            if ($isDefault) {
                if ($status !== BranchStatus::Active) {
                    throw ValidationException::withMessages([
                        'is_default' => 'An inactive branch cannot be the default branch.',
                    ]);
                }

                Branch::query()
                    ->forTenant($this->tenantContext->tenantId())
                    ->where('id', '!=', $locked->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $locked->forceFill([
                'code' => $code,
                'name' => $name,
                'status' => $status,
                'is_default' => $isDefault,
            ])->save();

            return $locked->refresh();
        });
    }
}
