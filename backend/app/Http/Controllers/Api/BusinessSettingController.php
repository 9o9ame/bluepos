<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalogProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Http\Resources\BusinessSettingResource;
use App\Models\Account;
use App\Tenancy\TenantContext;

class BusinessSettingController extends Controller
{
    public function show(TenantContext $tenantContext, TenantCatalogProvisioner $provisioner): BusinessSettingResource
    {
        $settings = $provisioner->provision($tenantContext->tenant());
        $this->authorize('view', $settings);

        return new BusinessSettingResource($settings->load('openingBalanceEquityAccount'));
    }

    public function update(
        UpdateBusinessSettingsRequest $request,
        TenantContext $tenantContext,
        TenantCatalogProvisioner $provisioner,
    ): BusinessSettingResource {
        $settings = $provisioner->provision($tenantContext->tenant());
        $this->authorize('update', $settings);

        $data = $request->validated();
        if (array_key_exists('opening_balance_equity_account_ulid', $data)) {
            $ulid = $data['opening_balance_equity_account_ulid'];
            unset($data['opening_balance_equity_account_ulid']);
            $settings->opening_balance_equity_account_id = $ulid
                ? Account::query()
                    ->forTenant($tenantContext->tenantId())
                    ->where('ulid', $ulid)
                    ->where('is_active', true)
                    ->value('id')
                : null;
        }

        $settings->fill($data);
        $settings->save();

        return new BusinessSettingResource($settings->fresh()->load('openingBalanceEquityAccount'));
    }
}
