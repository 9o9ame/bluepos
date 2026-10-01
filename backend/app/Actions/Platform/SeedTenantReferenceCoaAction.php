<?php

namespace App\Actions\Platform;

use App\Accounting\ReferenceCoaSeeder;
use App\Models\Tenant;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;

class SeedTenantReferenceCoaAction
{
    public function __construct(
        private readonly ReferenceCoaSeeder $seeder,
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    /**
     * @return array{main_heads: int, sub_heads: int, account_types: int, accounts: int}
     */
    public function execute(Tenant $tenant): array
    {
        $result = $this->seeder->seed($tenant);

        $this->audit->record('TENANT_REFERENCE_COA_SEEDED', [
            'resource_type' => 'tenant',
            'resource_ulid' => $tenant->ulid,
            'main_heads' => $result['main_heads'],
            'sub_heads' => $result['sub_heads'],
            'account_types' => $result['account_types'],
        ], null, $this->context->hasUser() ? $this->context->user() : null);

        return $result;
    }
}
