<?php


namespace OctavianParalescu\ApiController\Controllers;


use OctavianParalescu\ApiController\Converters\RequestConverter;

trait ApiShowTrait
{
    public function apiShow(RequestConverter $requestConverter, string $selectedModelClass, string $id)
    {
        $httpRequest = request();

        // Convert the http request parameters to a model query
        $query = $requestConverter->convert($selectedModelClass, $httpRequest, RequestConverter::API_ACTION_SHOW, $id);

        $resultArray = $query->get()->toArray();

        if (isset($resultArray[0])) {
            return $resultArray[0];
        } else {
            return null;
        }
    }
}