<?php

namespace Nidavellir\Trading\ApiSystems\Binance\REST;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait Market
{
    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Mark-Price
    public function markPrice(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->publicRequest(
            'GET',
            'fapi/v1/premiumIndex',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api
    public function ping()
    {
        return $this->publicRequest('GET', 'fapi/v1/ping');
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Check-Server-Time
    public function time()
    {
        return $this->publicRequest('GET', 'fapi/v1/time');
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Exchange-Information
    public function exchangeInfo(array $properties = [])
    {
        return $this->publicRequest('GET', '/fapi/v1/exchangeInfo', $properties);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Notional-and-Leverage-Brackets
    public function leverageBracket(array $properties = [])
    {
        return $this->signRequest('GET', 'fapi/v1/leverageBracket', $properties);
    }
}
