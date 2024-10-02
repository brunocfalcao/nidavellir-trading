<?php

namespace Nidavellir\Trading\ApiSystems\Binance\REST;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait Trade
{
    public function updateMarginType(array $properties = [])
    {
        return $this->signRequest('POST', '/fapi/v1/marginType', $properties);
    }

    public function getPositions()
    {
        return $this->signRequest('GET', '/fapi/v2/positionRisk');
    }

    public function newMultipleOrders(array $properties = [])
    {
        $validator = Validator::make($properties, [
            'options.batchOrders' => 'required|array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('POST', '/fapi/v1/batchOrders', $properties);
    }

    public function modifyOrder(array $properties)
    {
        $validator = Validator::make($properties, [
            'options.order_id' => 'required',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('PUT', '/fapi/v1/order', $properties);
    }

    public function newOrder(array $properties = [])
    {
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
            'options.side' => 'required|string',
            'options.type' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('POST', '/fapi/v1/order', $properties);
    }

    public function cancelOrder(array $properties = [])
    {
        $validator = Validator::make($properties['options'], [
            'symbol' => 'required',
            'orderId' => 'required',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('DELETE', '/fapi/v1/order', $properties);
    }

    public function cancelOpenOrders(array $properties = [])
    {
        $validator = Validator::make($properties['options'], [
            'symbol' => 'required',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('DELETE', '/fapi/v1/allOpenOrders', $properties);
    }

    public function cancelMultipleOrders(array $properties = [])
    {
        $validator = Validator::make($properties['options'], [
            'symbol' => 'required',
            'orderIdList' => 'required|array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('DELETE', '/fapi/v1/batchOrders', $properties);
    }

    public function getOrder(array $properties = [])
    {
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('GET', '/fapi/v1/order', $properties);
    }

    public function getOpenOrders(array $properties = [])
    {
        return $this->signRequest('GET', '/fapi/v1/openOrders', $properties);
    }

    public function getAllOrders(array $properties = [])
    {
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('GET', '/fapi/v1/allOrders', $properties);
    }

    public function account(array $properties = [])
    {
        return $this->signRequest('GET', '/fapi/v3/account', $properties);
    }

    public function setLeverage(array $properties = [])
    {
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
            'options.leverage' => 'required|integer',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest('POST', '/fapi/v1/leverage', $properties);
    }
}
