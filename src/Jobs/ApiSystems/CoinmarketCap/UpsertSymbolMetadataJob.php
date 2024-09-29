<?php

namespace Nidavellir\Trading\Jobs\ApiSystems\CoinmarketCap;

use Nidavellir\Trading\Abstracts\AbstractJob;
use Nidavellir\Trading\ApiSystems\ApiSystemRESTWrapper;
use Nidavellir\Trading\ApiSystems\CoinmarketCap\CoinmarketCapRESTMapper;
use Nidavellir\Trading\Exceptions\TryCatchException;
use Nidavellir\Trading\Models\Symbol;
use Nidavellir\Trading\Nidavellir;

class UpsertSymbolMetadataJob extends AbstractJob
{
    public function handle()
    {
        try {
            $api = new ApiSystemRESTWrapper(
                new CoinmarketCapRESTMapper(
                    credentials: Nidavellir::getSystemCredentials('coinmarketcap')
                )
            );

            $symbols = Symbol::whereNull('image_url')
                ->orWhereNull('description')
                ->pluck('coinmarketcap_id')
                ->toArray();

            foreach (array_chunk($symbols, 100) as $chunk) {
                $symbolList = implode(',', $chunk);

                $cryptoDataList = (array) $api->withOptions(['id' => $symbolList])
                    ->getSymbolsMetadata()['data'];

                if (! empty($cryptoDataList)) {
                    foreach ($cryptoDataList as $cryptoId => $cryptoData) {
                        $imageUrl = $cryptoData['logo'] ?? null;
                        $name = $cryptoData['name'] ?? null;
                        $website = $cryptoData['urls']['website'][0] ?? null;
                        $description = $cryptoData['description'] ?? null;

                        Symbol::where('coinmarketcap_id', $cryptoId)
                            ->where(function ($query) {
                                $query->whereNull('image_url')
                                    ->orWhereNull('description')
                                    ->orWhereNull('website');
                            })
                            ->update([
                                'name' => $name,
                                'website' => $website,
                                'image_url' => $imageUrl,
                                'description' => $description,
                            ]);
                    }
                }
            }
            $this->jobPollerInstance->markAsComplete();
        } catch (\Throwable $e) {
            $this->jobPollerInstance->markAsError($e);
            throw new TryCatchException(throwable: $e);
        }
    }
}
