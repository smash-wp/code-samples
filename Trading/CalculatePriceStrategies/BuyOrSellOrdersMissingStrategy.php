<?php

namespace App\Services\Trading\CalculatePriceStrategies;

use App\Services\Trading\TradingService;
use App\Transaction;
use Illuminate\Database\Eloquent\Collection;

class BuyOrSellOrdersMissingStrategy implements CalculatePriceStrategyInterface
{
    public function calculate(Collection $transactions)
    {
        $maxBuyPrice = $transactions->where('side', Transaction::SIDE_BUY)->max('price');
        $minSellPrice = $transactions->where('side', Transaction::SIDE_SALE)->min('price');

        if ($maxBuyPrice) {
            $from = $maxBuyPrice * (1 - TradingService::PRICE_RANGE_PERCENT);
            $to = $maxBuyPrice;
        } else {
            $from = $minSellPrice;
            $to = $minSellPrice * (1 + TradingService::PRICE_RANGE_PERCENT);
            ;
        }

        return [
            $transactions
                ->whereBetween('price', [$from, $to])
                ->sortBy('volume', SORT_REGULAR, (bool)$maxBuyPrice)
                ->first()
                ->price
            ,
            $from . '-' . $to
        ];
    }
}
