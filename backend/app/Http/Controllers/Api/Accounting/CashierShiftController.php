<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Accounting\CashierShiftLifecycle;
use App\Authz\PermissionService;
use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierShiftController extends Controller
{
    public function __construct(
        private readonly CashierShiftLifecycle $shifts,
        private readonly PermissionService $permissions,
    ) {}

    public function current(): JsonResponse
    {
        $this->requirePermission('cash_sessions.view');

        $shift = $this->shifts->current();

        return response()->json([
            'shift' => $shift ? $this->payload($shift) : null,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $this->requirePermission('cash_sessions.open');

        $data = $request->validate([
            'opening_cash' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->shifts->open(
            (string) $data['opening_cash'],
            isset($data['notes']) ? (string) $data['notes'] : null,
            (string) $request->header('Idempotency-Key', ''),
        );

        return response()->json($this->payload($shift), 201);
    }

    public function close(Request $request, string $shiftUlid): JsonResponse
    {
        $this->requirePermission('cash_sessions.close');

        $data = $request->validate([
            'actual_cash' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->shifts->close(
            $shiftUlid,
            (string) $data['actual_cash'],
            isset($data['notes']) ? (string) $data['notes'] : null,
            (string) $request->header('Idempotency-Key', ''),
        );

        return response()->json($this->payload($shift));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CashierShift $shift): array
    {
        $shift->loadMissing('membership.user');

        return [
            'ulid' => $shift->ulid,
            'status' => $shift->status->value,
            'operator' => [
                'membership_ulid' => $shift->membership?->ulid,
                'name' => $shift->membership?->user?->name
                    ?: $shift->membership?->username
                    ?: 'Unknown Operator',
            ],
            'opened_at' => $shift->opened_at?->toISOString(),
            'closed_at' => $shift->closed_at?->toISOString(),
            'opening_cash' => (string) $shift->opening_cash,
            'expected_cash' => $shift->expected_cash === null ? null : (string) $shift->expected_cash,
            'actual_cash' => $shift->actual_cash === null ? null : (string) $shift->actual_cash,
            'difference' => $shift->difference === null ? null : (string) $shift->difference,
            'notes' => $shift->notes,
        ];
    }

    private function requirePermission(string $permission): void
    {
        abort_unless($this->permissions->can($permission), 403);
    }
}
