<?php

namespace Nidavellir\Trading\Listeners\Positions;

use Nidavellir\Trading\Nidavellir;
use Nidavellir\Trading\Models\Order;
use Nidavellir\Trading\JobPollerManager;
use Nidavellir\Trading\Abstracts\AbstractListener;
use Nidavellir\Trading\Jobs\Positions\DispatchPositionJob;
use Nidavellir\Trading\Events\Positions\PositionCreatedEvent;

/**
 * Class: PositionCreatedListener
 *
 * This listener is triggered when a new position is created. It sets the
 * trade configuration for the position, creates the relevant orders based on
 * predefined ratios, and dispatches a job to finalize the position and prepare
 * it for order placement on the exchange.
 *
 * Important points:
 * - Updates the position with trade configuration.
 * - Creates Market, Limit, and Profit orders based on the configuration.
 * - Dispatches the position for further processing.
 */
class PositionCreatedListener extends AbstractListener
{
    // Handles the position creation event by updating the configuration,
    // creating the necessary orders, and dispatching the position job.
    public function handle(PositionCreatedEvent $event)
    {
        $position = $event->position;
        $trader = $position->trader;
        $configuration = Nidavellir::getTradeConfiguration();

        // Update the position with the trade configuration.
        $position->update([
            'trade_configuration' => $configuration,
        ]);

        // Loop through the order ratios from the configuration.
        // Create Market, Profit, and Limit orders accordingly.
        foreach ($configuration['orders']['ratios'] as $type => $ratio) {
            // Create Market and Profit orders, which have a single ratio.
            if ($type == 'MARKET' || $type == 'PROFIT') {
                $this->createOrder(
                    $position->id,
                    $type,
                    $ratio[0],
                    $ratio[1]
                );
            }

            // Create Limit orders, which can have multiple ratios.
            if ($type == 'LIMIT') {
                foreach ($ratio as $limitOrder) {
                    $this->createOrder(
                        $position->id,
                        $type,
                        $limitOrder[0],
                        $limitOrder[1]
                    );
                }
            }
        }

        // After creating all orders, dispatch the position job to prepare
        // the remaining trade data and sync it with the exchange.
        $jobPoller = new JobPollerManager;
        $jobPoller->newBlockUUID();
        $jobPoller->addJob(DispatchPositionJob::class, $position->id);
        $jobPoller->release();
    }

    // Creates an order associated with the given position, using the
    // provided type, price ratio, and amount divider.
    private function createOrder($positionId, $type, $pricePercentageRatio, $amountDivider)
    {
        // Create a new order associated with the position using given parameters.
        Order::create([
            'position_id' => $positionId,
            'type' => $type,
            'price_ratio_percentage' => $pricePercentageRatio,
            'amount_divider' => $amountDivider,
        ]);
    }
}
