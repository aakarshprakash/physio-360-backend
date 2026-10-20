<?php

namespace Database\Seeders;

use App\Auth\RoleRegistrar;
use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Required in every environment (safe to re-run): currencies with their
 * minor-unit exponents, and the role/permission matrix.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(RoleRegistrar $roles): void
    {
        $currencies = [
            ['INR', 'Indian Rupee', '₹', 2],
            ['AED', 'UAE Dirham', 'AED', 2],
            ['SAR', 'Saudi Riyal', 'SAR', 2],
            ['QAR', 'Qatari Riyal', 'QAR', 2],
            ['KWD', 'Kuwaiti Dinar', 'KWD', 3],
            ['BHD', 'Bahraini Dinar', 'BHD', 3],
            ['OMR', 'Omani Rial', 'OMR', 3],
            ['USD', 'US Dollar', '$', 2],
        ];

        foreach ($currencies as [$code, $name, $symbol, $exponent]) {
            Currency::query()->updateOrCreate(['code' => $code], compact('name', 'symbol', 'exponent'));
        }

        $roles->sync();
    }
}
