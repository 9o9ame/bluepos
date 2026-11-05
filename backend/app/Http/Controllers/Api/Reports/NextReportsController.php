<?php

namespace App\Http\Controllers\Api\Reports;

use App\Authz\PermissionService;
use App\Enums\JournalStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Reports\NextReportDocumentReader;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

final class NextReportsController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly NextReportDocumentReader $documents,
    ) {}

    private function authorizeReport(string $kind): void
    {
        $permission = $kind === 'advance' || $kind === 'product-transactions'
            ? 'reports.inventory' : 'reports.accounting';
        if (! $this->permissions->can($permission)) {
            throw new ApiException('FORBIDDEN', 'You cannot view this report.', 403);
        }
        if ($kind === 'advance' && ! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'Sales values require accounting reports permission.', 403);
        }
    }

    private function filters(Request $request, TenantContext $context, string $kind): array
    {
        $this->authorizeReport($kind);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'types' => ['sometimes', 'array', 'max:4'],
            'types.*' => ['required', Rule::in(['sale','purchase','sale_return','purchase_return'])],
            'product_ulid' => ['nullable', 'string', 'ulid', Rule::exists('products','ulid')
                ->where('tenant_id', $context->tenantId())],
            'account_ulid' => ['nullable', 'string', 'ulid', Rule::exists('accounts','ulid')
                ->where('tenant_id', $context->tenantId())],
            'format' => ['sometimes', Rule::in(['summary', 'daily', 'detail', 'short', 'invoice', 'ledger'])],
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from']);
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to']);
        if ($from->diffInDays($to) >= 31) {
            throw new ApiException('REPORT_DATE_RANGE_TOO_LARGE', 'Select no more than 31 calendar days.', 422);
        }
        return $data;
    }

    public function options(TenantContext $context): JsonResponse
    {
        if (! $this->permissions->can('reports.inventory') && ! $this->permissions->can('reports.accounting')) {
            throw new ApiException('FORBIDDEN', 'You cannot view report options.', 403);
        }
        return response()->json([
            'products' => Product::query()->forTenant($context->tenantId())
                ->orderBy('product_number')->limit(500)->get(['ulid','name','product_number']),
            'accounts' => $this->permissions->can('reports.accounting')
                ? Account::query()->forTenant($context->tenantId())
                    ->where('is_active', true)->orderBy('name')->limit(500)
                    ->get(['ulid','name','code'])
                : [],
            'types' => ['sale','purchase','sale_return','purchase_return'],
        ]);
    }

    public function advance(Request $request, TenantContext $context): JsonResponse
    {
        $f = $this->filters($request, $context, 'advance');
        $docs = $this->documents->read($context, $f['from'], $f['to'], ['sale']);
        $productFilter = $f['product_ulid'] ?? null;
        $rows = [];
        $productUlids = [];
        foreach ($docs as $doc) {
            foreach ($doc['lines'] as $line) {
                $product = $line['product'] ?? null;
                if (! $product || ($productFilter && $product['ulid'] !== $productFilter)) continue;
                $productUlids[] = $product['ulid'];
                $rows[] = [
                    'document_ulid' => $doc['ulid'], 'line_ulid' => $line['ulid'],
                    'number' => $doc['number'], 'date' => $doc['date'],
                    'product' => $product, 'sold' => $line['base_quantity'],
                    'stock_at' => null, 'current_stock' => null,
                    'rate' => $line['rate'], 'amount' => $line['amount'],
                ];
            }
        }
        $products = Product::query()->forTenant($context->tenantId())
            ->whereIn('ulid', array_unique($productUlids))->get(['id','ulid'])->keyBy('id');
        $ids = $products->keys()->all();
        $balances = StockBalance::query()->forTenant($context->tenantId())
            ->where('branch_id',$context->branchId())->where('warehouse_id',$context->warehouseId())
            ->whereIn('product_id',$ids)->get()->keyBy('product_id');
        $movements = StockMovement::query()->forTenant($context->tenantId())
            ->where('branch_id',$context->branchId())->where('warehouse_id',$context->warehouseId())
            ->whereIn('product_id',$ids)
            ->where('occurred_at','<=',CarbonImmutable::createFromFormat('Y-m-d', $f['to'])->endOfDay())
            ->orderBy('occurred_at')->orderBy('id')->get();
        $running = [];
        $lineStock = [];
        foreach ($movements as $m) {
            $running[$m->product_id] = bcadd($running[$m->product_id] ?? '0.000000',(string) $m->quantity,6);
            if ($m->reference_line_ulid) $lineStock[$m->reference_line_ulid] = $running[$m->product_id];
        }
        $byUlid = $products->keyBy('ulid');
        $total = '0.0000'; $qty = '0.000000';
        foreach ($rows as &$row) {
            $product = $byUlid->get($row['product']['ulid']);
            $row['stock_at'] = $lineStock[$row['line_ulid']] ?? null;
            $row['current_stock'] = $product ? (string) ($balances->get($product->id)?->quantity ?? '0.000000') : null;
            $total = bcadd($total,$row['amount'],4);
            $qty = bcadd($qty,$row['sold'],6);
        }
        unset($row);
        return response()->json([
            'rows'=>$rows,'totals'=>['quantity'=>$qty,'amount'=>$total],
            'limitations'=>[
                'Stock@ is signed stock after the matching posted movement; unavailable where no movement-line match exists.',
                'Current Stock is the present warehouse balance, not a historical balance at the report date.',
                'Line amounts may not include invoice-level discounts; they are not invoice gross totals.',
            ],
        ]);
    }

    public function daybook(Request $request, TenantContext $context): JsonResponse
    {
        $f = $this->filters($request,$context,'daybook');
        $docs = $this->documents->read($context,$f['from'],$f['to'],$f['types'] ?? []);
        $groups = []; $totals = [];
        foreach (['sale','purchase','sale_return','purchase_return'] as $type) {
            $groups[$type] = [];
            $totals[$type] = '0.0000';
        }
        foreach ($docs as $d) {
            $groups[$d['type']][] = [
                'ulid'=>$d['ulid'],'date'=>$d['date'],'number'=>$d['number'],
                'party'=>$d['party_name'],'amount'=>$d['amount'],
            ];
            $totals[$d['type']] = bcadd($totals[$d['type']],$d['amount'],4);
        }
        // Show posted standalone vouchers separately from operational sales/purchases.
        // Journal debit/credit legs are not added to invoice gross totals.
        $voucherTypes = [
            JournalEntry::DOCUMENT_PAYMENT_VOUCHER,
            JournalEntry::DOCUMENT_RECEIVING_VOUCHER,
            JournalEntry::DOCUMENT_JOURNAL_VOUCHER,
        ];
        $voucherQuery = JournalEntry::query()->forTenant($context->tenantId())
            ->where('branch_id',$context->branchId())
            ->where('status',JournalStatus::Posted->value)
            ->whereIn('document_type',$voucherTypes)
            ->whereBetween('entry_date',[$f['from'],$f['to']])
            ->with('lines')->orderBy('entry_date')->orderBy('id');
        $groups['voucher'] = [];
        $totals['voucher_debits'] = '0.0000';
        $totals['voucher_credits'] = '0.0000';
        foreach ($voucherQuery->get() as $entry) {
            $debits = '0.0000'; $credits = '0.0000';
            foreach ($entry->lines as $line) {
                $debits = bcadd($debits,(string)$line->debit,4);
                $credits = bcadd($credits,(string)$line->credit,4);
            }
            $groups['voucher'][] = [
                'ulid'=>$entry->ulid,'date'=>$entry->entry_date->toDateString(),
                'number'=>$entry->voucher_number,'party'=>$entry->description,
                'amount'=>$debits,
            ];
            $totals['voucher_debits'] = bcadd($totals['voucher_debits'],$debits,4);
            $totals['voucher_credits'] = bcadd($totals['voucher_credits'],$credits,4);
        }
        $ledger = null;
        if (($f['format'] ?? '') === 'ledger') {
            if (empty($f['account_ulid'])) {
                throw new ApiException('ACCOUNT_REQUIRED','Select a ledger account.',422);
            }
            $account = Account::query()->forTenant($context->tenantId())
                ->where('ulid',$f['account_ulid'])->firstOrFail();
            $base = JournalEntry::query()->forTenant($context->tenantId())
                ->where('branch_id',$context->branchId())
                ->where('status',JournalStatus::Posted->value);
            $before = (clone $base)->whereDate('entry_date','<',$f['from'])
                ->with(['lines'=>fn ($q)=>$q->where('account_id',$account->id)])->get();
            $opening = '0.0000';
            foreach ($before as $j) foreach ($j->lines as $line) {
                $opening = bcadd($opening,bcsub((string)$line->debit,(string)$line->credit,4),4);
            }
            $journals = (clone $base)->whereBetween('entry_date',[$f['from'],$f['to']])
                ->with(['lines'=>fn ($q)=>$q->where('account_id',$account->id)])
                ->orderBy('entry_date')->orderBy('id')->get();
            $running = $opening; $ledgerRows = [];
            foreach ($journals as $j) foreach ($j->lines as $line) {
                $running = bcadd($running,bcsub((string)$line->debit,(string)$line->credit,4),4);
                $ledgerRows[] = [
                    'ulid'=>$line->ulid,'date'=>$j->entry_date->toDateString(),
                    'number'=>$j->voucher_number,'type'=>$j->document_type,
                    'description'=>$line->description ?? $j->description,
                    'debit'=>(string)$line->debit,'credit'=>(string)$line->credit,'balance'=>$running,
                ];
            }
            $ledger = [
                'account'=>['ulid'=>$account->ulid,'code'=>$account->code,'name'=>$account->name],
                'opening'=>$opening,'closing'=>$running,'rows'=>$ledgerRows,
            ];
        }
        return response()->json([
            'rows'=>$groups,'totals'=>$totals,'ledger'=>$ledger,
            'limitations'=>[
                'Document groups are operational posted totals; voucher ledger uses posted journal lines only.',
                'Missing accounting journals are not inferred or posted by this read-only report.',
            ],
        ]);
    }

    public function productTransactions(Request $request, TenantContext $context): JsonResponse
    {
        $f = $this->filters($request,$context,'product-transactions');
        $start = CarbonImmutable::createFromFormat('Y-m-d',$f['from'])->startOfDay();
        $end = CarbonImmutable::createFromFormat('Y-m-d',$f['to'])->endOfDay();
        $q = StockMovement::query()->forTenant($context->tenantId())
            ->where('branch_id',$context->branchId())->where('warehouse_id',$context->warehouseId())
            ->whereBetween('occurred_at',[$start,$end])
            ->with('product:id,ulid,name,product_number')
            ->orderBy('occurred_at')->orderBy('id');
        if (! empty($f['product_ulid'])) {
            $p = Product::query()->forTenant($context->tenantId())->where('ulid',$f['product_ulid'])->firstOrFail();
            $q->where('product_id',$p->id);
        }
        if ((clone $q)->limit(5001)->count()>5000) {
            throw new ApiException('REPORT_TOO_LARGE','Select a shorter range (over 5000 movements).',422);
        }
        $canCost = $this->permissions->can('inventory.view');
        $rows=[];$in='0.000000';$out='0.000000';
        foreach ($q->get() as $m) {
            $qty=(string)$m->quantity;
            $inQty=bccomp($qty,'0',6)>0?$qty:'0.000000';
            $outQty=bccomp($qty,'0',6)<0?bcmul($qty,'-1',6):'0.000000';
            $in=bcadd($in,$inQty,6);$out=bcadd($out,$outQty,6);
            $rows[]=[
                'ulid'=>$m->ulid,'date'=>$m->occurred_at->toDateString(),
                'type'=>$m->movement_type->value,
                'document_ulid'=>$m->reference_ulid,
                'product'=>$m->product?->only(['ulid','name','product_number']),
                'quantity_in'=>$inQty,'quantity_out'=>$outQty,
                'unit_cost'=>$canCost?$m->unit_cost:null,'total_cost'=>$canCost?$m->total_cost:null,
            ];
        }
        return response()->json([
            'rows'=>$rows,'totals'=>['in'=>$in,'out'=>$out,'net'=>bcsub($in,$out,6)],
            'limitations'=>[
                'These are append-only stock movements, including amendments and adjustments.',
                'Movement cost is historical stock cost, NOT sale revenue. Cost is hidden without inventory.view.',
            ],
        ]);
    }

    public function detailed(Request $request, TenantContext $context): JsonResponse
    {
        $f = $this->filters($request,$context,'detailed');
        $docs=$this->documents->read($context,$f['from'],$f['to'],$f['types'] ?? []);
        $format=$f['format'] ?? 'summary';
        if (! empty($f['account_ulid'])) {
            throw new ApiException('PARTY_FILTER_NOT_SUPPORTED',
                'Select document types and dates; an account ID is not a party ID.',422);
        }
        if (! in_array($format,['summary','daily','detail'],true)) {
            throw new ApiException('REPORT_FORMAT_INVALID','Invalid detailed report format.',422);
        }
        $rows=[];$totals=['amount'=>'0.0000','count'=>0];
        foreach ($docs as $d) {
            $sign=in_array($d['type'],['sale_return','purchase_return'],true)?'-1':'1';
            $signed=bcmul($d['amount'],$sign,4);
            $totals['amount']=bcadd($totals['amount'],$signed,4);$totals['count']++;
            $key=$format==='daily'?($d['type'].'|'.$d['party_name'])
                :($format==='summary'?($d['type'].'|'.$d['party_name']):$d['ulid']);
            if (! isset($rows[$key])) {
                $rows[$key]=[
                    'date'=>$format==='daily'?$d['date']:$d['date'],
                    'type'=>$d['type'],
                    'party'=>$d['party_name'],'number'=>$format==='detail'?$d['number']:null,
                    'count'=>0,'amount'=>'0.0000','lines'=>[], 'days'=>[],
                ];
            }
            $rows[$key]['count']++;
            $rows[$key]['amount']=bcadd($rows[$key]['amount'],$signed,4);
            if ($format==='daily') $rows[$key]['days'][$d['date']] = bcadd(
                $rows[$key]['days'][$d['date']] ?? '0.0000', $signed, 4
            );
            if ($format==='detail') $rows[$key]['lines']=$d['lines'];
        }
        return response()->json([
            'format'=>$format,'rows'=>array_values($rows),'totals'=>$totals,
            'limitations'=>[
                'Day-by-day format groups dated posted documents, not a historical tax-period reallocation.',
                'Returns are signed negative; sales and purchases are separate transaction kinds.',
            ],
        ]);
    }

    public function fbr(Request $request, TenantContext $context, string $register): JsonResponse
    {
        if (! in_array($register,['sales','purchases'],true)) abort(404);
        $f=$this->filters($request,$context,'fbr');
        $types=$register==='sales'?['sale','sale_return']:['purchase','purchase_return'];
        $docs=$this->documents->read($context,$f['from'],$f['to'],$types);
        $productFilter=$f['product_ulid'] ?? null;
        $rows=[];$total=['value'=>'0.0000','tax'=>'0.0000','gross'=>'0.0000'];
        $partyIds=[];
        foreach ($docs as $d) if ($d['party']['ulid'] ?? null) $partyIds[]=$d['party']['ulid'];
        $partyClass=$register==='sales'?\App\Models\Customer::class:\App\Models\Supplier::class;
        $parties=$partyClass::query()->forTenant($context->tenantId())
            ->whereIn('ulid',array_unique($partyIds))->get()->keyBy('ulid');
        foreach ($docs as $d) foreach ($d['lines'] as $line) {
            if ($productFilter && ($line['product']['ulid'] ?? null)!==$productFilter) continue;
            $sign=str_ends_with($d['type'],'return')?'-1':'1';
            $tax=bcadd($line['tax'],$line['further_tax'],4);
            $value=bcsub($line['amount'],$tax,4);
            $signedValue=bcmul($value,$sign,4);
            $signedTax=bcmul($tax,$sign,4);
            $gross=bcmul($line['amount'],$sign,4);
            $party=$parties->get($d['party']['ulid'] ?? '');
            $rows[]=[
                'document_ulid'=>$d['ulid'],'line_ulid'=>$line['ulid'],
                'date'=>$d['date'],'number'=>$d['number'],'type'=>$d['type'],
                'party'=>$d['party_name'],'party_ntn'=>$party?->ntn,
                'party_strn'=>$party?->stn,
                'product'=>$line['product'],'hs_code'=>$line['hs_code'],
                'quantity'=>bcmul($line['quantity'],$sign,6),
                'rate'=>$line['rate'],'tax_rate'=>$line['tax_rate'],
                'value'=>$signedValue,'tax'=>$signedTax,'gross'=>$gross,
            ];
            $total['value']=bcadd($total['value'],$signedValue,4);
            $total['tax']=bcadd($total['tax'],$signedTax,4);
            $total['gross']=bcadd($total['gross'],$gross,4);
        }
        $settings=BusinessSetting::query()->forTenant($context->tenantId())->first();
        return response()->json([
            'register'=>$register,'rows'=>$rows,'totals'=>$total,
            'registered_number'=>$settings?->tax_registration_number,
            'compliance_ready'=>false,
            'limitations'=>[
                'INTERNAL TAX REVIEW ONLY: not an FBR Annex A/C upload or digital invoice.',
                'Invoice-level discounts, freight, withholding and some taxes are not allocated to individual lines.',
                'Verify taxable value, registration, buyer/supplier details and the current official filing schema before filing.',
            ],
        ]);
    }
}
