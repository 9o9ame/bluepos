# BluePOS Accounting Engine

BluePOS uses **double-entry accounting**. Debits and credits are not decorative columns on unrelated tables. Every posted financial document produces a `journal_entries` row and two or more `journal_lines`.

**Invariant:** for every posted journal, `SUM(debit) = SUM(credit)`. Unbalanced journals are never committed.

Party and account balances are **derived from posted journal lines**. There is no cashier-editable `customers.balance` or `vendors.balance`.

Companion: `docs/DATABASE.md`, `docs/INVENTORY.md`.

---

## 1. Ledger model

```text
accounts (hierarchical COA)
  └── journal_entries (header: date, document, status, voucher_number)
        └── journal_lines (account, debit XOR credit, optional customer/vendor)
```

Vouchers (`vouchers` / `voucher_lines`) are the user-facing document for payments, receipts, journals, contra, expenses, debit/credit notes. Posting a voucher always posts a journal.

Operational documents (sale, purchase, return, stock adjustment) call `AccountingService` inside the same database transaction as stock movements.

---

## 2. Account types and seeded COA

Types: Assets, Liabilities, Equity, Revenue, Expenses.  
Subtypes used by posting: `cash`, `bank`, `card`, `wallet`, `inventory`, `receivable`, `payable`, `cogs`, `tax_payable`, `sales`, `sales_return`, `purchase` (optional), `expense`.

On tenant provision, seed at least:

```text
ASSETS
  CASH → Cash In Hand
  BANK → Bank Accounts
  CARD CLEARING
  WALLET CLEARING
  ACCOUNTS RECEIVABLE
  INVENTORY
LIABILITIES
  ACCOUNTS PAYABLE
  TAX PAYABLE
EQUITY
  CAPITAL
  OPENING BALANCE EQUITY
  RETAINED EARNINGS
REVENUE
  SALES
  OTHER INCOME
  SALES RETURNS (contra-revenue, credit-normal inverted via debit on returns)
EXPENSES
  COST OF GOODS SOLD
  SALARIES, RENT, ELECTRICITY, TRANSPORT, REPAIR, MISC
```

`business_settings` stores the system account IDs used by posters (`inventory_account_id`, `sales_account_id`, `cogs_account_id`, `receivable_account_id`, `payable_account_id`, `cash_account_id`, `tax_payable_account_id`, default bank/card/wallet).

---

## 3. Journal header and lines

Header: `ulid`, `tenant_id`, `branch_id`, `document_type`, `document_id`, `voucher_number`, `date`, `description`, `status` (`draft`,`posted`), `created_by`, `approved_by`, `posted_at`, optional `reverses_journal_entry_id`.

Lines: `account_id`, `description`, `debit NUMERIC(20,4)`, `credit NUMERIC(20,4)`, optional `customer_id` / `vendor_id`.

Rules:

- Exactly one of debit/credit is non-zero per line (the other is 0).
- Line amounts ≥ 0.
- Poster runs `assertBalanced()`; throws `UNBALANCED_JOURNAL` and rolls back.
- Status: **`draft` | `posted` only.**
- Once `posted`, the journal and its lines are **immutable**. Do not change original debit/credit. Do not set the original to a different status to “hide” it.
- Correction: insert a **new** `posted` journal whose `reverses_journal_entry_id` points at the original. Original remains `posted`.
- Financial reports include **all `posted` journals** (original + reversal net to zero for that pair). **Exclude `draft`.**

**v1 VOID of a sale/purchase/voucher:**

1. Lock operational document (`posted`).
2. Post reversing stock movements if stock was affected.
3. Post a **new** reversing journal (`reverses_journal_entry_id` = original journal id). Original journal stays `posted`; lines untouched.
4. Set operational document `voided`.
5. Never DELETE rows. Never edit original journal lines.

---

## 4. Money calculation

Never trust client totals. `SaleCalculator` / equivalent uses working scale 8, then the rounding steps in `docs/DATABASE.md` §14.4.

PHP: BCMath or an exact decimal value object. Persist money `NUMERIC(20,4)`, quantity `NUMERIC(20,6)`, percentages/rates `NUMERIC(12,8)`. Frontend is preview only. Laravel recomputes price, quantity, discount, tax, line totals, invoice totals, payment allocation, COGS, and profit.

---

## 5. Document numbering

`document_sequences` allocated with `lockForUpdate()` **after** validation and inside the posting transaction. Offline local numbers are display-only. `ulid` / `client_ulid` never changes.

---

## 6. Customer and vendor balances

```text
customer_balance = Σ journal_lines.debit − Σ journal_lines.credit
                   WHERE customer_id = X AND account.subtype = receivable
                   AND journal posted
```

(Sign convention: AR is debit-normal. Positive = customer owes us.)

Vendor (AP credit-normal):

```text
vendor_balance = Σ credit − Σ debit on payable lines for vendor_id
```

Opening balances are journals dated in the opening financial year, not a standing field.

POS must show **Available credit** = `credit_limit − balance` using ledger (or labeled snapshot when offline).

---

## 7. Journal posting examples

Amounts are illustrative. Tax omitted unless noted. Cash account = Cash In Hand. All in `NUMERIC` scale 4.

### 7.1 Cash sale (net 1,000, COGS 700)

Sale cash 1,000; cost 700.

| Account | Debit | Credit |
|---|---:|---:|
| Cash In Hand | 1000.0000 | |
| Sales | | 1000.0000 |
| COGS | 700.0000 | |
| Inventory | | 700.0000 |

Two logical journals may be stored as **one** journal with four lines (preferred) so one document maps to one entry.

### 7.2 Credit sale (net 1,000, COGS 700)

| Account | Debit | Credit |
|---|---:|---:|
| Accounts Receivable (customer) | 1000.0000 | |
| Sales | | 1000.0000 |
| COGS | 700.0000 | |
| Inventory | | 700.0000 |

### 7.3 Sale return of the cash sale (return 1,000, restore COGS 700)

| Account | Debit | Credit |
|---|---:|---:|
| Sales Returns (or Sales) | 1000.0000 | |
| Cash In Hand (or AR if credit return) | | 1000.0000 |
| Inventory | 700.0000 | |
| COGS | | 700.0000 |

Cash vs AR on the credit side follows how the original was paid / how refund is settled.

### 7.4 Cash purchase (1,500 inventory)

| Account | Debit | Credit |
|---|---:|---:|
| Inventory | 1500.0000 | |
| Cash In Hand | | 1500.0000 |

If tax is recoverable, split input tax to a tax asset/liability per tenant tax settings.

### 7.5 Credit purchase (1,500)

| Account | Debit | Credit |
|---|---:|---:|
| Inventory | 1500.0000 | |
| Accounts Payable (vendor) | | 1500.0000 |

Landed cost (freight, loading): debit Inventory (capitalize) or expense per setting. **v1 default:** capitalize into inventory and average cost.

### 7.6 Purchase return (500)

| Account | Debit | Credit |
|---|---:|---:|
| Accounts Payable (or Cash if refunded) | 500.0000 | |
| Inventory | | 500.0000 |

### 7.7 Customer receipt (800 against AR)

| Account | Debit | Credit |
|---|---:|---:|
| Cash In Hand (or Bank) | 800.0000 | |
| Accounts Receivable (customer) | | 800.0000 |

Receipt voucher document + journal.

### 7.8 Supplier payment (800)

| Account | Debit | Credit |
|---|---:|---:|
| Accounts Payable (vendor) | 800.0000 | |
| Cash / Bank | | 800.0000 |

### 7.9 Expense payment (rent 200)

| Account | Debit | Credit |
|---|---:|---:|
| Rent Expense | 200.0000 | |
| Cash / Bank | | 200.0000 |

If paid on account to a vendor, credit AP instead of cash, then pay AP separately.

### 7.10 Stock adjustment

**Increase** (found stock, cost 100):

| Account | Debit | Credit |
|---|---:|---:|
| Inventory | 100.0000 | |
| Inventory Adjustment Gain / Opening Equity / Expense contra | | 100.0000 |

**Decrease** (shortage, cost 100):

| Account | Debit | Credit |
|---|---:|---:|
| Inventory Adjustment Loss (expense) | 100.0000 | |
| Inventory | | 100.0000 |

Damage/expiry use the same pattern with distinct expense accounts if configured.

---

## 8. COGS posting (separate explanation)

COGS is **not** taken from the client. After `lockForUpdate()` on `stock_balances`:

1. Determine quantity in **base units** (barcode conversion).
2. Read `avg_cost` (moving weighted average).
3. `cogs = qty × avg_cost` (BCMath).
4. Persist `sale_items.unit_cost` and line COGS from this figure.
5. Journal: Dr COGS / Cr Inventory for that amount.
6. Decrease `stock_balances.qty`. Average cost on remaining qty unchanged for a sale (weighted average updates on **inbound** at different cost).

Purchase inbound:

```text
new_avg = (old_qty × old_avg + in_qty × in_cost) / (old_qty + in_qty)
```

Sale does not change `avg_cost`. Return-to-stock of a sale typically restores qty at the **original line cost** (not a new average dilution). **v1:** restore at `sale_items.unit_cost` and recompute average:

```text
new_avg = (old_qty × old_avg + ret_qty × original_line_cost) / (old_qty + ret_qty)
```

FIFO is out of scope for v1; the inventory service should keep a costing strategy interface.

Gross profit reports: `net_sales − COGS` from posted journals/lines, not from POS screen math.

---

## 9. Account entry transaction report (mandatory)

Every posted `journal_lines` row, joined to header:

Columns: Date, Voucher No, Document Type, Reference, Narration, Account, Customer/Vendor, Debit, Credit, Running Balance, Created By, Branch.

Filters: date, account, customer, vendor, voucher type, branch, user, document type, amount range.

Drill-down: `document_type` + `document_id` → source ULID.

Running balance computed per account (and optionally per party) in date/id order. Server-side; export PDF/CSV.

---

## 10. Voucher types

Payment, Receipt, Journal, Contra, Cash Payment, Cash Receipt, Bank Payment, Bank Receipt, Debit Note, Credit Note, Expense.

Each: voucher #, date, accounts, party, method, amount, narration, reference, branch, attachments, created/approved by. Post → journal. Void → reversal.

Contra: cash ↔ bank, both balance-sheet, still balanced.

---

## 11. Financial year

`financial_years`: start/end, `is_closed`. Closing does **not** copy, archive-off, or truncate journals. History remains queryable.

Posting into a closed period is blocked (`PERIOD_CLOSED`) except an explicit authorized adjustment (`settings.financial` / `period.adjust`).

---

## 11A. Report status filters

| Report | Include |
|---|---|
| Sales operational (qty, turnover) | Sales `posted` only, **minus** posted returns and voided sales (voided excluded; returns as negative/separate) |
| Accounting TB, P/L, ledger, account-entry | All journals with status `posted` (includes reversal journals). **Exclude `draft`.** Original posted journals are never deleted or line-edited. |
| Inventory qty/valuation | All `stock_movements` (including reversals). Never draft documents. |
| Cash session | Posted cash sales/receipts/payments in the session window |

Never let hold/draft/local-completed-unsynced tickets into financial reports.

---

---

## 12. Tests

- Unbalanced journal cannot post.
- Each example 7.1–7.10 posts with Σ debit = Σ credit.
- Customer balance equals AR lines, not a column write.
- Void sale reverses stock and journals; original rows remain.
- Same idempotency key does not duplicate journals.
- Account entry report running balance matches a fixture.
