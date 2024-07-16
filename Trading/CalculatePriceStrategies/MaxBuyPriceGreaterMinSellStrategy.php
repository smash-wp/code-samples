<?php

namespace App\Services\Trading\CalculatePriceStrategies;

use App\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

class MaxBuyPriceGreaterMinSellStrategy implements CalculatePriceStrategyInterface
{
    public function calculate(Collection $transactions)
    {
        $turnovers = [];

        /** @var Transaction $transaction */
        foreach ($transactions as $transaction) {
            if (in_array($transaction->price, array_keys($turnovers))) {
                continue;
            }

            $price = $transaction->price;

            $supplyTotalSharesByPrice = abs(
                $transactions
                    ->where('side', Transaction::SIDE_SALE)
                    ->where('price', '<=', $price)
                    ->sum('volume')
            );

            $demandTotalSharesByPrice = abs(
                $transactions
                    ->where('side', Transaction::SIDE_BUY)
                    ->where('price', '>=', $price)
                    ->sum('volume')
            );

            $turnoverTotalShares = $supplyTotalSharesByPrice - $demandTotalSharesByPrice;

            if ($turnoverTotalShares > 0) {
                $turnoverTotalShares = $demandTotalSharesByPrice;
            } else {
                $turnoverTotalShares = $supplyTotalSharesByPrice;
            }

            if ($turnoverTotalShares) {
                $turnovers[(string)$price] = [
                    'price' => (string)$price,
                    'turnover' => $turnoverTotalShares,
                    'imbalance' => abs($supplyTotalSharesByPrice - $demandTotalSharesByPrice),
                ];
            }
        }

        $turnovers = collect($turnovers);

        $minImbalance = Arr::get($turnovers->sortBy('imbalance')->first(), 'imbalance');

        $totalVolumeByImbalance = $turnovers->where('imbalance', $minImbalance)->sum(function ($item) {
            return $item['price'] * $item['turnover'];
        });

        $priceByImbalance = $turnovers->where('imbalance', $minImbalance)->sum(function ($item) {
            return $item['turnover'];
        });

        return $totalVolumeByImbalance / $priceByImbalance;
    }
}
