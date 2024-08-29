<?php

namespace Nidavellir\Trading\Exchanges\Binance;

use Nidavellir\Trading\Abstracts\AbstractMapper;
use Nidavellir\Trading\Exchanges\Binance\Callers\GetExchangeInformation;
use Nidavellir\Trading\Exchanges\Binance\REST\Futures;
use Nidavellir\Trading\Models\Exchange;

/**
 * The Mapper translates actions into methods, in a standard
 * way, due to the fact that the exchange api methods can
 * have different name and parameter signatures.
 */
class BinanceRESTMapper extends AbstractMapper
{
    /**
     * Returns the exchange model instance by canonical.
     */
    public function exchange(): Exchange
    {
        return Exchange::firstWhere('canonical', 'binance');
    }

    /**
     * Returns FUTURES credentials.
     */
    public function credentials(): array
    {
        return [
            'url' => $this->exchange()->futures_url_rest_prefix,
            'secret' => $this->credentials['secret_key'],
            'key' => $this->credentials['api_key'],
        ];
    }

    public function getExchangeInformation(array $options = [], array $data = [])
    {
        return (new GetExchangeInformation($this, $options, $data))->result;
    }

    /**
     * Returns the Futures account balance, only for the
     * ones that are not zero.
     *
     * Return:
     * ['ETH' => 6.24,
     *  'USDT' => 330.11]
     */
    public function getAccountBalance()
    {
        $futures = new Futures($this->credentials());

        $portfolio = $futures->getAccountBalance();

        // Remove zero balances, and keep only the others.
        $filteredPortfolio = array_filter($portfolio, function ($item) {
            return (float) $item['availableBalance'] !== 0.0;
        });

        // Map the result.
        $result = [];

        foreach ($filteredPortfolio as $item) {
            $result[$item['asset']] = (float) $item['availableBalance'];
        }

        return $result;
    }

    /**
     * Places an order on the system, via REST api call.
     * string $symbol, string $side, string $type, array $options = []
     * ['symbol-currency'=> '', (SOL-USDT)
     *  'side' => '', BUY/SELL
     *  'type' => '' MARKET/LIMIT,
     *  'quantity' => 500 (USDT),
     *  'price' => 45.56 (USDT)
     */
    public function newOrder(array $options)
    {
        $connection = new Futures($this->credentials());

        if (! array_key_exists('timeInForce', $options)) {
            $options['timeinforce'] = 'GTC';
        }

        return $connection->newOrder(
            symbol: $options['symbol'],
            side: $options['side'],
            type: $options['type'],
            options: $options
        );
    }
}
