<?php

namespace App\Platform\Imports;

use App\Models\Platform\PlatformUser;
use App\Models\Tenant;
use App\Platform\PlatformAuditLogger;
use App\Security\AuditLogger;
use Illuminate\Http\Request;

/** Attribute reused inventory action events to the real platform actor. */
final class ImportAuditLogger extends AuditLogger
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly PlatformUser $actor,
        private readonly PlatformAuditLogger $platformAudit,
    ) {}

    public function record(string $event, array $metadata = [], ?Request $request = null): void
    {
        $this->platformAudit->record($event, [...$metadata, 'tenant_ulid' => $this->tenant->ulid,
            'execution_context' => 'platform_tenant_import'], $request, $this->actor);
    }
}
