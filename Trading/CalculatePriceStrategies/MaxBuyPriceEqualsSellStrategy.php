<?php

namespace App\Services\Trading\CalculatePriceStrategies;

use App\Transaction;
use Illuminate\Database\Eloquent\Collection;

class MaxBuyPriceEqualsSellStrategy implements CalculatePriceStrategyInterface
{
    public function calculate(Collection $transactions)
    {
        return $transactions->where('side', Transaction::SIDE_BUY)->max('price');
    }
}
