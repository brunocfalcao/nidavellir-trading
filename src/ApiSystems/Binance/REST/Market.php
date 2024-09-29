<?php

namespace Nidavellir\Trading\ApiSystems\Binance\REST;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait Market
{
    public function markPrice(array $properties = [])
    {
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->publicRequest('GET', 'fapi/v1/premiumIndex', $properties);
    }

    public function ping()
    {
        return $this->publicRequest('GET', 'fapi/v1/ping');
    }

    public function time()
    {
        return $this->publicRequest('GET', 'fapi/v1/time');
    }

    public function exchangeInfo(array $properties = [])
    {
        return $this->publicRequest('GET', '/fapi/v1/exchangeInfo', $properties);
    }

    public function leverageBracket(array $properties = [])
    {
        return $this->signRequest('GET', 'fapi/v1/leverageBracket', $properties);
    }
}
