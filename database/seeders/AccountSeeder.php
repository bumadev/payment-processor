<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Merchant;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        if (!Account::where('type', 'cash_pending')->exists()) {
            $cashPending = new Account();
            $cashPending->type = 'cash_pending';
            $cashPending->name = 'Cash Pending';
            $cashPending->save();
        }

        Merchant::all()->each(function (Merchant $merchant) {
            $exists = Account::where('type', 'merchant_balance')
                ->where('merchant_id', $merchant->id)
                ->exists();

            if ($exists) {
                return;
            }

            $merchantBalance = new Account();
            $merchantBalance->type = 'merchant_balance';
            $merchantBalance->merchant_id = $merchant->id;
            $merchantBalance->name = "Merchant Balance - {$merchant->name}";
            $merchantBalance->save();
        });
    }
}
