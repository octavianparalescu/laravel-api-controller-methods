<?php


namespace OctavianParalescu\ApiController\Controllers;


use OctavianParalescu\ApiController\Converters\RequestConverter;

trait ApiIndexTrait
{
    public function apiIndex(RequestConverter $requestConverter, string $selectedModelClass)
    {
        $httpRequest = request();

        // Convert the http request parameters to a model query
        $conversionResult = $requestConverter->convert($selectedModelClass, $httpRequest, RequestConverter::API_ACTION_INDEX);
        
        $request = $conversionResult['request'];
        $query = $conversionResult['query'];

        // Paginate the results
        $entities = $query->paginate($httpRequest->per_page);

        // Add the existing query parameter to the current/next/previous page url
        $entities->appends($httpRequest->query());

        return array_merge($entities->toArray(), ['request' => $request]);
    }
}
