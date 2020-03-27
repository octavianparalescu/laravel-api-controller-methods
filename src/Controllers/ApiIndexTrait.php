<?php


namespace OctavianParalescu\ApiController\Controllers;


use OctavianParalescu\ApiController\Converters\RequestConverter;

trait ApiIndexTrait
{
    public function apiIndex(RequestConverter $requestConverter, string $selectedModelClass)
    {
        $httpRequest = request();

        // Convert the http request parameters to a model query
        $query = $requestConverter->convert($selectedModelClass, $httpRequest, RequestConverter::API_ACTION_INDEX);

        // Paginate the results
        $entities = $query->paginate($httpRequest->per_page);

        // Add the existing query parameter to the current/next/previous page url
        $entities->appends($httpRequest->query());

        return $entities->toArray();
    }
}
