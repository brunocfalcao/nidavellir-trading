<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exceptions\SymbolNotSyncedException;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

class UpsertSymbolsJob extends AbstractJob
{
    private ?int $limit;

    public function __construct(?int $limit = null)
    {
        $this->limit = $limit;
    }

    public function handle()
    {
        try {
            $api = new ApiSystemRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            if ($this->limit) {
                $api->withOptions(['limit' => $this->limit]);
            }

            $data = $api->getSymbols()['data'];

            if (! $data) {
                throw new SymbolNotSyncedException(
                    message: 'No symbols fetched from CoinMarketCap API'
                );
            }

            $symbolUpdates = array_map(function ($item) {
                return [
                    'coinmarketcap_id' => $item['id'],
                    'name' => $item['name'],
                    'rank' => $item['rank'],
                    'token' => $item['symbol'],
                    'updated_at' => now(),
                ];
            }, $data);

            Symbol::upsert(
                $symbolUpdates,
                ['coinmarketcap_id'],
                ['name', 'token', 'updated_at']
            );

            $this->jobPollerInstance->markAsComplete();
        } catch (\Throwable $e) {
            $this->jobPollerInstance->markAsError($e);
            throw new TryCatchException(throwable: $e);
        }
    }
}
