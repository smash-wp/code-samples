<?php


use App\Company;
use App\DTO\Trading\SignOfStocksRequestDTO;
use App\DTO\Trading\UpdateMarketOrderDTO;
use App\DTO\Transaction\SearchQueryDTO;
use App\DTO\TransactionEvent\SearchQueryDTO as TransactionEventSearchDTO;
use App\Enums\TradingCase;
use App\Models\Trading\TradingOrderDepthHistory;
use App\Services\ActivityLogService;
use App\Services\CompanyProfileAccessService;
use App\Services\Trading\CalculatePriceStrategies\BuyOrSellOrdersMissingStrategy;
use App\Services\Trading\CalculatePriceStrategies\MaxBuyPriceEqualsSellStrategy;
use App\Services\Trading\CalculatePriceStrategies\MaxBuyPriceGreaterMinSellStrategy;
use App\Services\Trading\CalculatePriceStrategies\MaxBuyPriceLowerMinSellStrategy;
use App\Services\TransactionEventService;
use App\Services\TransactionService;
use App\TradingPeriod;
use App\Transaction;
use App\TransactionEvent;
use App\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use League\Csv\Writer;
use Spatie\Activitylog\Models\Activity;

class TradingService
{
    public const BUY_FEE_NVR = 2;
    public const BUY_FEE_EUROCLEAR = 2;
    public const MINIMUM_FEE_NVR = 100;

    public const SELL_FEE_NVR = 2;
    public const SELL_FEE_EUROCLEAR = 2;
    public const MINIMUM_FEE_EUROCLEAR = 250;

    public const PHASES_FOR_EDITING_ORDERS_BY_USER = [
        TransactionEvent::PHASE_STATUS_ONE,
        TransactionEvent::PHASE_STATUS_THREE,
    ];

    public const PRICE_RANGE_PERCENT = 0.2;

    public function __construct(
        private TransactionService $transactionService,
        private ActivityLogService $activityLogService,
        private TransactionEventService $transactionEventService
    ) {
    }

    public function calculatePrice(TransactionEvent $event): array
    {
        $transactions = $this->transactionService->getMarketOrders(new SearchQueryDTO(
            [
                'transactionEventsIds' => [$event->id],
                'all' => true,
                'onlyFilled' => false,
            ]
        ));

        if ($transactions->isEmpty()) {
            return [0, null, null];
        }

        $maxBuyPrice = $transactions->where('side', Transaction::SIDE_BUY)->max('price');
        $minSellPrice = $transactions->where('side', Transaction::SIDE_SALE)->min('price');

        if (!$maxBuyPrice || !$minSellPrice) {
            $tradingCase = TradingCase::BUY_OR_SELL_ORDERS_MISSING;
            $calcPriceStrategy = new BuyOrSellOrdersMissingStrategy();
        } elseif ($maxBuyPrice === $minSellPrice) {
            $tradingCase = TradingCase::MAX_BUY_PRICE_EQUALS_MIN_SELL_PRICE;
            $calcPriceStrategy = new MaxBuyPriceEqualsSellStrategy();
        } elseif ($maxBuyPrice < $minSellPrice) {
            $tradingCase = TradingCase::MAX_BUY_PRICE_LESS_MIN_SELL_PRICE;
            $calcPriceStrategy = new MaxBuyPriceLowerMinSellStrategy();
        } else {
            $calcPriceStrategy = new MaxBuyPriceGreaterMinSellStrategy();
            $tradingCase = TradingCase::MAX_BUY_PRICE_GREATER_MIN_SELL_PRICE;
        }

        $result = $calcPriceStrategy->calculate($transactions);

        if (!is_array($result)) {
            $result = [$result, ''];
        }

        list($price, $range) = $result;

        return [$price, $tradingCase, $range];
    }
}
