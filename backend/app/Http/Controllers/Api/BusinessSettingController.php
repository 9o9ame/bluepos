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

        return new BusinessSettingResource($settings->load(['openingBalanceEquityAccount', 'defaultCashAccount', 'salesClearingAccount', 'purchaseClearingAccount']));
    }

    public function update(
        UpdateBusinessSettingsRequest $request,
        TenantContext $tenantContext,
        TenantCatalogProvisioner $provisioner,
    ): BusinessSettingResource {
        $settings = $provisioner->provision($tenantContext->tenant());
        $this->authorize('update', $settings);

        $data = $request->validated();
        foreach ([
            'opening_balance_equity_account_ulid' => 'opening_balance_equity_account_id',
            'default_cash_account_ulid' => 'default_cash_account_id',
            'sales_clearing_account_ulid' => 'sales_clearing_account_id',
            'purchase_clearing_account_ulid' => 'purchase_clearing_account_id',
        ] as $ulidField => $idField) {
            if (! array_key_exists($ulidField, $data)) {
                continue;
            }

            $ulid = $data[$ulidField];
            unset($data[$ulidField]);

            $settings->{$idField} = $ulid
                ? Account::query()
                    ->forTenant($tenantContext->tenantId())
                    ->where('ulid', $ulid)
                    ->where('is_active', true)
                    ->value('id')
                : null;
        }

        $settings->fill($data);
        $settings->save();

        return new BusinessSettingResource($settings->fresh()->load(['openingBalanceEquityAccount', 'defaultCashAccount', 'salesClearingAccount', 'purchaseClearingAccount']));
    }
}
