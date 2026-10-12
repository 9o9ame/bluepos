<?php

namespace App\Http\Controllers\Api;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\ReportHeading;
use App\Tenancy\TenantContext;
use DOMDocument;
use DOMElement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportHeadingController extends Controller
{
    private const DEFAULTS = [
        ['head_id' => 'Head', 'used_for' => 'Default Report Heading'],
        ['head_id' => 'HeadGrid', 'used_for' => 'GRID HEADING'],
        ['head_id' => 'HeadLand', 'used_for' => 'HeadLand'],
        ['head_id' => 'POS', 'used_for' => 'POS'],
        ['head_id' => 'ProdTemplate', 'used_for' => 'ProdTemplate'],
        ['head_id' => 'ZapSaleFooter', 'used_for' => 'ZapSaleFooter'],
    ];

    private const DEFAULT_LAYOUT = [
        'margin' => 'normal',
        'orientation' => 'portrait',
        'page_size' => 'a4',
        'columns' => 1,
        'line_numbers' => false,
        'page_color' => '#ffffff',
    ];

    public function index(
        PermissionService $permissions,
        TenantContext $tenantContext,
    ): JsonResponse {
        $this->requirePermission($permissions, 'settings.view');
        $this->provisionDefaults($tenantContext);

        $rows = ReportHeading::query()
            ->forTenant($tenantContext->tenantId())
            ->orderBy('id')
            ->get();

        return response()->json($rows->map(fn (ReportHeading $row) => $this->resource($row))->values());
    }

    public function store(
        Request $request,
        PermissionService $permissions,
        TenantContext $tenantContext,
    ): JsonResponse {
        $this->requirePermission($permissions, 'settings.manage');

        $data = $request->validate([
            'head_id' => [
                'required',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('report_headings', 'head_id')
                    ->where('tenant_id', $tenantContext->tenantId()),
            ],
            'used_for' => ['required', 'string', 'max:120'],
            'content_html' => ['nullable', 'string', 'max:50000'],
            'layout_settings' => ['sometimes', 'array'],
            'layout_settings.margin' => ['sometimes', Rule::in(['normal', 'narrow', 'wide'])],
            'layout_settings.orientation' => ['sometimes', Rule::in(['portrait', 'landscape'])],
            'layout_settings.page_size' => ['sometimes', Rule::in(['a4', 'letter', 'legal'])],
            'layout_settings.columns' => ['sometimes', 'integer', Rule::in([1, 2, 3])],
            'layout_settings.line_numbers' => ['sometimes', 'boolean'],
            'layout_settings.page_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $row = ReportHeading::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            'head_id' => trim($data['head_id']),
            'used_for' => trim($data['used_for']),
            'content_html' => $this->sanitizeHtml($data['content_html'] ?? null),
            'layout_settings' => $this->sanitizeLayout($data['layout_settings'] ?? null),
        ]);

        return response()->json($this->resource($row), 201);
    }

    public function update(
        Request $request,
        string $reportHeadingUlid,
        PermissionService $permissions,
        TenantContext $tenantContext,
    ): JsonResponse {
        $this->requirePermission($permissions, 'settings.manage');

        $row = ReportHeading::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $reportHeadingUlid)
            ->firstOrFail();

        $data = $request->validate([
            'head_id' => [
                'sometimes',
                'required',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('report_headings', 'head_id')
                    ->where('tenant_id', $tenantContext->tenantId())
                    ->ignore($row->id),
            ],
            'used_for' => ['sometimes', 'required', 'string', 'max:120'],
            'content_html' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'layout_settings' => ['sometimes', 'array'],
            'layout_settings.margin' => ['sometimes', Rule::in(['normal', 'narrow', 'wide'])],
            'layout_settings.orientation' => ['sometimes', Rule::in(['portrait', 'landscape'])],
            'layout_settings.page_size' => ['sometimes', Rule::in(['a4', 'letter', 'legal'])],
            'layout_settings.columns' => ['sometimes', 'integer', Rule::in([1, 2, 3])],
            'layout_settings.line_numbers' => ['sometimes', 'boolean'],
            'layout_settings.page_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        if (array_key_exists('head_id', $data)) {
            $row->head_id = trim((string) $data['head_id']);
        }
        if (array_key_exists('used_for', $data)) {
            $row->used_for = trim((string) $data['used_for']);
        }
        if (array_key_exists('content_html', $data)) {
            $row->content_html = $this->sanitizeHtml($data['content_html']);
        }
        if (array_key_exists('layout_settings', $data)) {
            $row->layout_settings = $this->sanitizeLayout($data['layout_settings']);
        }
        $row->save();

        return response()->json($this->resource($row->refresh()));
    }

    public function destroy(
        string $reportHeadingUlid,
        PermissionService $permissions,
        TenantContext $tenantContext,
    ): JsonResponse {
        $this->requirePermission($permissions, 'settings.manage');

        $row = ReportHeading::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $reportHeadingUlid)
            ->firstOrFail();

        $row->delete();

        return response()->json(['ok' => true]);
    }

    public function copyToOthers(
        string $reportHeadingUlid,
        PermissionService $permissions,
        TenantContext $tenantContext,
    ): JsonResponse {
        $this->requirePermission($permissions, 'settings.manage');

        return DB::transaction(function () use ($reportHeadingUlid, $tenantContext): JsonResponse {
            $source = ReportHeading::query()
                ->forTenant($tenantContext->tenantId())
                ->where('ulid', $reportHeadingUlid)
                ->lockForUpdate()
                ->firstOrFail();

            $updated = ReportHeading::query()
                ->forTenant($tenantContext->tenantId())
                ->where('id', '!=', $source->id)
                ->update([
                    'content_html' => $source->content_html,
                    'layout_settings' => $source->layout_settings,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'ok' => true,
                'updated' => $updated,
            ]);
        });
    }

    private function provisionDefaults(TenantContext $tenantContext): void
    {
        $settings = BusinessSetting::query()
            ->forTenant($tenantContext->tenantId())
            ->first();

        $defaultContent = null;
        if ($settings) {
            $lines = array_values(array_filter([
                $settings->business_name ? '<strong>'.e($settings->business_name).'</strong>' : null,
                $settings->legal_name ? e($settings->legal_name) : null,
                $settings->address ? e($settings->address) : null,
                $settings->city ? e($settings->city) : null,
                $settings->phone ? e($settings->phone) : null,
            ]));

            if ($lines !== []) {
                $defaultContent = '<p>'.implode('<br>', $lines).'</p>';
            }
        }

        DB::transaction(function () use ($tenantContext, $defaultContent): void {
            foreach (self::DEFAULTS as $index => $default) {
                ReportHeading::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenantContext->tenantId(),
                        'head_id' => $default['head_id'],
                    ],
                    [
                        'used_for' => $default['used_for'],
                        'content_html' => $index === 0 ? $defaultContent : null,
                        'layout_settings' => self::DEFAULT_LAYOUT,
                    ],
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>|null  $layout
     * @return array{margin:string,orientation:string,page_size:string,columns:int,line_numbers:bool,page_color:string}
     */
    private function sanitizeLayout(?array $layout): array
    {
        $layout ??= [];

        return [
            'margin' => in_array($layout['margin'] ?? null, ['normal', 'narrow', 'wide'], true)
                ? $layout['margin']
                : self::DEFAULT_LAYOUT['margin'],
            'orientation' => in_array($layout['orientation'] ?? null, ['portrait', 'landscape'], true)
                ? $layout['orientation']
                : self::DEFAULT_LAYOUT['orientation'],
            'page_size' => in_array($layout['page_size'] ?? null, ['a4', 'letter', 'legal'], true)
                ? $layout['page_size']
                : self::DEFAULT_LAYOUT['page_size'],
            'columns' => in_array($layout['columns'] ?? null, [1, 2, 3], true)
                ? $layout['columns']
                : self::DEFAULT_LAYOUT['columns'],
            'line_numbers' => is_bool($layout['line_numbers'] ?? null)
                ? $layout['line_numbers']
                : self::DEFAULT_LAYOUT['line_numbers'],
            'page_color' => is_string($layout['page_color'] ?? null)
                && preg_match('/^#[0-9A-Fa-f]{6}$/', $layout['page_color']) === 1
                ? strtolower($layout['page_color'])
                : self::DEFAULT_LAYOUT['page_color'],
        ];
    }

    private function sanitizeHtml(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $allowed = '<p><div><br><hr><strong><b><em><i><u><s><strike><ul><ol><li><h1><h2><h3><span>';
        $clean = strip_tags($html, $allowed);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><body>'.$clean.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($doc->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $safeStyle = $this->sanitizeStyle($element);

            while ($element->attributes->length > 0) {
                $element->removeAttributeNode($element->attributes->item(0));
            }

            if ($safeStyle !== null) {
                $element->setAttribute('style', $safeStyle);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return null;
        }

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }

        return trim($output) !== '' ? trim($output) : null;
    }

    private function sanitizeStyle(DOMElement $element): ?string
    {
        $style = (string) $element->getAttribute('style');
        if ($style === '') {
            return null;
        }

        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);
            if ($property === null || $value === null) {
                continue;
            }

            $property = strtolower(trim($property));
            $value = trim($value);

            if ($element->tagName === 'span') {
                if (
                    $property === 'font-family'
                    && in_array($value, [
                        'Inter',
                        'Arial',
                        'Calibri',
                        'Georgia',
                        'Times New Roman',
                        'Verdana',
                        'Courier New',
                    ], true)
                ) {
                    $safe[] = 'font-family: '.$value;
                    continue;
                }

                if (
                    $property === 'font-size'
                    && in_array($value, [
                        '8px', '9px', '10px', '11px', '12px', '14px', '16px',
                        '18px', '20px', '24px', '28px', '32px',
                    ], true)
                ) {
                    $safe[] = 'font-size: '.$value;
                    continue;
                }

                if (
                    in_array($property, ['color', 'background-color'], true)
                    && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
                ) {
                    $safe[] = $property.': '.strtolower($value);
                }

                continue;
            }

            if (
                in_array($element->tagName, ['p', 'div', 'h1', 'h2', 'h3'], true)
                && $property === 'text-align'
                && in_array($value, ['left', 'center', 'right', 'justify'], true)
            ) {
                $safe[] = 'text-align: '.$value;
            }
        }

        return $safe === [] ? null : implode('; ', $safe);
    }

    /**
     * @return array{
     *   ulid:string,
     *   head_id:string,
     *   used_for:string,
     *   content_html:?string,
     *   layout_settings:array{margin:string,orientation:string,page_size:string,columns:int,line_numbers:bool,page_color:string}
     * }
     */
    private function resource(ReportHeading $row): array
    {
        return [
            'ulid' => $row->ulid,
            'head_id' => $row->head_id,
            'used_for' => $row->used_for,
            'content_html' => $row->content_html,
            'layout_settings' => $this->sanitizeLayout($row->layout_settings),
        ];
    }

    private function requirePermission(PermissionService $permissions, string $permission): void
    {
        if (! $permissions->can($permission)) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to manage report headings.', 403);
        }
    }
}
