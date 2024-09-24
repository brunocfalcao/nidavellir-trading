<?php

namespace Nidavellir\Trading\ApiSystems\Binance\REST;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait Trade
{
    public function updateMarginType(array $properties = [])
    {
        return $this->signRequest(
            'POST',
            '/fapi/v1/marginType',
            $properties
        );
    }

    //https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Position-Information-V2
    public function getPositions()
    {
        return $this->signRequest(
            'GET',
            '/fapi/v2/positionRisk'
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Place-Multiple-Orders
    public function newMultipleOrders(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties, [
            'options.batchOrders' => 'required|array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'POST',
            '/fapi/v1/batchOrders',
            $properties
        );
    }

    //https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Modify-Order
    public function modifyOrder(array $properties)
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties, [
            'options.order_id' => 'required',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'PUT',
            '/fapi/v1/order',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api
    public function newOrder(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
            'options.side' => 'required|string',
            'options.type' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'POST',
            '/fapi/v1/order',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Cancel-Order
    public function cancelOrder(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties['options'], [
            'symbol' => 'required',
            'orderId' => 'required',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'DELETE',
            '/fapi/v1/order',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Cancel-Multiple-Orders
    public function cancelMultipleOrders(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties['options'], [
            'symbol' => 'required',
            'orderIdList' => 'required|array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'DELETE',
            '/fapi/v1/batchOrders',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Cancel-All-Open-Orders
    public function cancelOpenOrders(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'DELETE',
            '/fapi/v1/openOrders',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Query-Order
    public function getOrder(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'GET',
            '/fapi/v1/order',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Current-All-Open-Orders
    public function openOrders(array $properties = [])
    {
        return $this->signRequest('GET', '/fapi/v1/openOrders', $properties);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/All-Orders
    public function allOrders(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'GET',
            '/fapi/v1/allOrders',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Account-Information-V3
    public function account(array $properties = [])
    {
        return $this->signRequest(
            'GET',
            '/fapi/v3/account',
            $properties
        );
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Change-Initial-Leverage
    public function setLeverage(array $properties = [])
    {
        // Validate the properties using the Validator facade
        $validator = Validator::make($properties, [
            'options.symbol' => 'required|string',
            'options.leverage' => 'required|integer',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->signRequest(
            'POST',
            '/fapi/v1/leverage',
            $properties
        );
    }
}
