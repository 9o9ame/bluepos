<?php

namespace App\Accounting;

use App\Models\Account;

class AccountBalanceCalculator
{
    public function signedEffect(Account $account, string $debit, string $credit): string
    {
        $account->loadMissing('accountType');

        if ($account->accountType?->is_payable) {
            return bcsub($this->money($credit), $this->money($debit), 4);
        }

        return bcsub($this->money($debit), $this->money($credit), 4);
    }

    public function money(string $value): string
    {
        return bcadd($value, '0', 4);
    }
}
