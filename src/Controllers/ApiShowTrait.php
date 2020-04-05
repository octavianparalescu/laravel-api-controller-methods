<?php


namespace OctavianParalescu\ApiController\Controllers;


use OctavianParalescu\ApiController\Converters\RequestConverter;

trait ApiShowTrait
{
    public function apiShow(RequestConverter $requestConverter, string $selectedModelClass, string $id)
    {
        $httpRequest = request();

        // Convert the http request parameters to a model query
        $conversionResult = $requestConverter->convert($selectedModelClass, $httpRequest, RequestConverter::API_ACTION_SHOW, $id);

        $request = $conversionResult['request'];
        $query = $conversionResult['query'];

        $resultArray = $query->get()->toArray();

        if (isset($resultArray[0])) {
            return array_merge(['object' => $resultArray[0]], ['request' => $request]);
        } else {
            return ['object' => null, 'request' => $request];
        }
    }
}