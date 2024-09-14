<?php

namespace Nidavellir\Trading\Jobs\Tests;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\Exceptions\OrderNotSyncedException;
use Nidavellir\Trading\Models\Position;
use Nidavellir\Trading\NidavellirException;
use Throwable;

/**
 * HardcodeMarketOrderJob creates a market order for testing
 * purposes. It tests the full cycle of creating a market
 * order and a corresponding profit order.
 */
class HardcodeMarketOrderJob extends AbstractJob
{
    // Constants for different order types: Market, Limit, and Profit.
    public const ORDER_TYPE_MARKET = 'MARKET';

    public const ORDER_TYPE_LIMIT = 'LIMIT';

    public const ORDER_TYPE_PROFIT = 'PROFIT';

    // Stores the ID of the position associated with the order.
    public $positionId;

    // Holds the order object being processed.
    public $order;

    /**
     * Constructor to initialize the job with the position ID.
     *
     * @param  int  $positionId  The ID of the position for which
     *                           the market order will be created.
     */
    public function __construct(int $positionId)
    {
        // Set the position ID.
        $this->positionId = $positionId;
    }

    /**
     * Handles the job execution. Creates or updates the market
     * order by retrieving the necessary position data, computing
     * the order amount, and updating the order status.
     */
    public function handle()
    {
        try {
            // Retrieve the position using the position ID.
            $position = Position::find($this->positionId);

            // Get the initial mark price of the position.
            $orderPrice = $position->initial_mark_price;

            // Retrieve the first market order for this position.
            $this->order = $position->orders->firstWhere('type', 'MARKET');

            // Prepare the order data for the market order.
            $orderData = [
                'side' => 'buy', // hardcoded for testing.
                'type' => 'MARKET',
                'quantity' => $this->computeOrderAmount($this->order, $orderPrice),
                'symbol' => $this->order
                                 ->position
                                 ->exchangeSymbol
                                 ->symbol
                                 ->token.'USDT',
            ];

            // Update the market order with the calculated details.
            $this->order->update([
                'status' => 'synced',
                'price' => $orderPrice,
                'api_order_id' => rand(1582606909, 1882606909), // Random ID for testing.
            ]);
        } catch (Throwable $e) {
            // Throw a NidavellirException instead of OrderNotSyncedException.
            throw new NidavellirException(
                originalException: $e,
                loggable: $this->order,
                additionalData: ['position_id' => $this->positionId]
            );
        }
    }

    /**
     * Computes the order amount based on the order type, price,
     * leverage, and total trade amount.
     *
     * - For MARKET and LIMIT order types, the token amount is
     *   calculated based on leverage and total trade amount.
     * - For PROFIT or other types, a hardcoded value is returned.
     */
    private function computeOrderAmount($order, $price)
    {
        // Retrieve the exchange symbol associated with the position.
        $exchangeSymbol = $order->position->exchangeSymbol;

        // Handles both MARKET and LIMIT order types.
        if (in_array($order->type, [self::ORDER_TYPE_MARKET, self::ORDER_TYPE_LIMIT])) {
            // Calculate the trade amount divided by the configured amount divider.
            $amountAfterDivider = $order->position->total_trade_amount / $order->amount_divider;

            // Adjust the trade amount based on the leverage.
            $amountAfterLeverage = $amountAfterDivider * $order->position->leverage;

            // Calculate the token amount to buy based on the price and precision.
            $tokenAmountToBuy = $amountAfterLeverage / $price;

            // Round the token amount to the required precision and return it.
            return round($tokenAmountToBuy, $exchangeSymbol->precision_quantity);
        }

        // For PROFIT or other order types, return a hardcoded value.
        return 100;
    }
}
