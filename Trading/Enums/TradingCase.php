<?php


enum TradingCase: int
{
    case MAX_BUY_PRICE_EQUALS_MIN_SELL_PRICE = 1;
    case BUY_OR_SELL_ORDERS_MISSING = 2;
    case MAX_BUY_PRICE_LESS_MIN_SELL_PRICE = 3;
    case MAX_BUY_PRICE_GREATER_MIN_SELL_PRICE = 4;

    public function label(): string
    {
        return match ($this) {
            self::BUY_OR_SELL_ORDERS_MISSING => 'Kurs ensidiga ordrar',
            self::MAX_BUY_PRICE_GREATER_MIN_SELL_PRICE => 'Kurs korsande ordrar',
            self::MAX_BUY_PRICE_LESS_MIN_SELL_PRICE => 'Kurs ej mötande ordrar',
            self::MAX_BUY_PRICE_EQUALS_MIN_SELL_PRICE => 'Kurs mötande ordrar'
        };
    }
}
