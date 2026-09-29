<?php

namespace App\Http\Resources;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalEntry
 */
class OpeningBalanceResource extends JsonResource
{
    /**
     * @param  array{balance: string, closing: string, debit: string, credit: string, narration: string|null, leaf_account_ulid: string}  $extra
     */
    public function __construct($resource, private readonly array $extra = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'status' => $this->status->value,
            'opening_date' => optional($this->entry_date)?->format('Y-m-d'),
            'narration' => $this->extra['narration'] ?? $this->description,
            'debit' => $this->extra['debit'] ?? '0.0000',
            'credit' => $this->extra['credit'] ?? '0.0000',
            'balance' => $this->extra['balance'] ?? '0.0000',
            'closing' => $this->extra['closing'] ?? '0.0000',
            'leaf_account_ulid' => $this->extra['leaf_account_ulid'] ?? null,
            'sales_person' => null,
            'posted_at' => optional($this->posted_at)?->toIso8601String(),
        ];
    }
}
