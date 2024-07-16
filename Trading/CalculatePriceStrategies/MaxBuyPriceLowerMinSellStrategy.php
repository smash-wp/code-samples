<?php

namespace App\Services\Trading\CalculatePriceStrategies;

use App\Services\Trading\TradingService;
use App\Transaction;
use Illuminate\Database\Eloquent\Collection;

class MaxBuyPriceLowerMinSellStrategy implements CalculatePriceStrategyInterface
{
    public function calculate(Collection $transactions)
    {
        $maxBuyPrice = $transactions->where('side', Transaction::SIDE_BUY)->max('price');
        $minSellPrice = $transactions->where('side', Transaction::SIDE_SALE)->min('price');

        $buyPriceRange = [$maxBuyPrice * (1 - TradingService::PRICE_RANGE_PERCENT), $maxBuyPrice];
        $sellPriceRange = [$minSellPrice, $minSellPrice * (1 + TradingService::PRICE_RANGE_PERCENT)];

        $buyOrdersTotalVolume = $transactions
            ->where('side', Transaction::SIDE_BUY)
            ->whereBetween('price', $buyPriceRange)
            ->sum('volume')
        ;

        $sellOrdersTotalVolume = abs($transactions
            ->where('side', Transaction::SIDE_SALE)
            ->whereBetween('price', $sellPriceRange)
            ->sum('volume'))
        ;

        return $buyOrdersTotalVolume > $sellOrdersTotalVolume ? $maxBuyPrice : $minSellPrice;
    }
}
