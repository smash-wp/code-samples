<?php

namespace App\Services\Trading\CalculatePriceStrategies;

use Illuminate\Database\Eloquent\Collection;

interface CalculatePriceStrategyInterface
{
    public function calculate(Collection $transactions);
}
