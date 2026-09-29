<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Parties\PartyBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartyBulkController extends Controller
{
    public function __construct(private readonly PartyBulkService $bulk) {}

    public function update(Request $request): JsonResponse
    {
        $this->authorizeBulkMutate();

        $payload = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*' => ['required', 'array'],
        ]);

        $result = $this->bulk->bulkUpdate($payload['rows']);

        return response()->json($result);
    }

    public function template(): StreamedResponse
    {
        $this->authorizeBulkView();
        $binary = $this->bulk->templateBinary();

        return response()->streamDownload(function () use ($binary): void {
            echo $binary;
        }, 'bluepos-parties-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeBulkMutate();
        $request->validate([
            'file' => ['required', 'file', 'max:2048'],
        ]);
        $file = $request->file('file');
        if (! $file) {
            throw new ApiException('VALIDATION_FAILED', 'Spreadsheet file is required.', 422);
        }

        return response()->json($this->bulk->previewExcel($file));
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizeBulkMutate();
        $request->validate([
            'file' => ['required', 'file', 'max:2048'],
            'confirm' => ['required', 'accepted'],
        ]);
        $file = $request->file('file');
        if (! $file) {
            throw new ApiException('VALIDATION_FAILED', 'Spreadsheet file is required.', 422);
        }

        $result = $this->bulk->importExcel($file);

        return response()->json($result);
    }

    private function authorizeBulkView(): void
    {
        if (! ($this->canAny(['suppliers.view', 'suppliers.manage', 'vendors.view'])
            || $this->canAny(['customers.view', 'customers.manage'])
            || $this->canAny(['accounts.view', 'accounts.manage']))) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to export party templates.', 403);
        }
    }

    private function authorizeBulkMutate(): void
    {
        if (! ($this->canAny(['suppliers.edit', 'suppliers.manage'])
            || $this->canAny(['customers.edit', 'customers.manage'])
            || $this->canAny(['accounts.edit', 'accounts.manage']))) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to bulk update parties.', 403);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canAny(array $permissions): bool
    {
        $svc = app(\App\Authz\PermissionService::class);
        foreach ($permissions as $permission) {
            if ($svc->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
