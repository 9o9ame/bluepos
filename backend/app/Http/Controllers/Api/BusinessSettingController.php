<?php

namespace App\Http\Controllers\Api;

use App\Catalog\TenantCatalogProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Http\Resources\BusinessSettingResource;
use App\Models\BusinessSetting;
use App\Tenancy\TenantContext;

class BusinessSettingController extends Controller
{
    public function show(TenantContext $tenantContext, TenantCatalogProvisioner $provisioner): BusinessSettingResource
    {
        $settings = $provisioner->provision($tenantContext->tenant());
        $this->authorize('view', $settings);

        return new BusinessSettingResource($settings);
    }

    public function update(
        UpdateBusinessSettingsRequest $request,
        TenantContext $tenantContext,
        TenantCatalogProvisioner $provisioner,
    ): BusinessSettingResource {
        $settings = $provisioner->provision($tenantContext->tenant());
        $this->authorize('update', $settings);

        $settings->fill($request->validated());
        $settings->save();

        return new BusinessSettingResource($settings->fresh());
    }
}
