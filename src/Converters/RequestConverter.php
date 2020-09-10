<?php


namespace OctavianParalescu\ApiController\Converters;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestConverter
{
    const API_ACTION_INDEX = 'index';
    const API_ACTION_SHOW = 'show';
    const FILTER_PATTERN = '/([a-zA-Z_]+) ?([><=]|[>=]|[<=]|[<>]|[!=]|[<=>]|like|not like|null|not null)[\s]?(.*)?/i';
    const ALL_FIELDS_SELECTOR = '*';

    /**
     * Converts the HTTP Request parameters to a Model Query for the
     * index Unauthenticated controller
     *
     * @param string      $mainResourceModel
     * @param Request     $httpRequest
     * @param string      $apiAction
     * @param string|null $id
     *
     * @return array
     */
    public function convert(string $mainResourceModel, Request $httpRequest, string $apiAction, string $id = null): array
    {
        $sortableByConstantName = $mainResourceModel . '::SORTABLE_BY';
        $sortableBy = defined($sortableByConstantName) ? constant($sortableByConstantName) : [];
        $selectableConstantName = $mainResourceModel . '::CAN_SELECT';
        $selectable = defined($selectableConstantName) ? constant($selectableConstantName) : [self::ALL_FIELDS_SELECTOR];

        /** @var Builder $query */
        $query = call_user_func([$mainResourceModel, 'query']);

        $mainResourceName = Str::singular(
            $query->getModel()
                  ->getTable()
        );

        $request = $this->extractQueryParametersFromHttpRequest(
            $mainResourceModel,
            $httpRequest,
            $apiAction,
            $sortableBy,
            $mainResourceName,
            $selectable
        );

        $query = $this->applyApiRequestParametersToQuery(
            $mainResourceModel,
            $apiAction,
            $request,
            $mainResourceName,
            $query,
            $id
        );

        return ['request' => $request, 'query' => $query];
    }

    /**
     * @param string  $mainResourceModel
     * @param Request $httpRequest
     * @param string  $apiAction
     * @param         $sortableBy
     * @param string  $mainResourceName
     * @param         $selectable
     *
     * @return array
     */
    private function extractQueryParametersFromHttpRequest(
        string $mainResourceModel,
        Request $httpRequest,
        string $apiAction,
        $sortableBy,
        string $mainResourceName,
        $selectable
    ): array {
        $request = [];
        $request['selected_fields'] = [];
        $request['sorting'] = [];
        $request['filters'] = [];
        $request['limits'] = [];
        $request['errors'] = [];

        // Extracts sorting request
        $request = $this->extractSortingParameters($httpRequest->query('sorting'), $sortableBy, $request, $mainResourceName);

        // Extracts query fields
        $request = $this->extractSelectedFields(
            $httpRequest->query('fields'),
            $request,
            $mainResourceModel,
            $mainResourceName,
            $selectable
        );

        // Extracts filters
        $request['filters'] = $this->extractFilters($httpRequest->query('filters'));

        $request = $this->extractSelectedFieldsFromFilters($request);
        $request = $this->extractEagerLoadedResources($request, $mainResourceName, $mainResourceModel);

        if ($apiAction === self::API_ACTION_INDEX) {
            $request = $this->extractLimitParameters($httpRequest->query('limit'), $request);
        }

        return $request;
    }

    /**
     * @param string      $mainResourceModel
     * @param string      $apiAction
     * @param array       $request
     * @param string      $mainResourceName
     * @param Builder     $query
     * @param string|null $id
     *
     * @return Builder
     */
    private function applyApiRequestParametersToQuery(
        string $mainResourceModel,
        string $apiAction,
        array $request,
        string $mainResourceName,
        Builder $query,
        string $id = null
    ): Builder {
        $query = $this->selectEagerLoadedResources($request['selected_fields'], $request['limits'], $mainResourceName, $query);
        $query = $this->selectMainResource($query, $request, $mainResourceName);

        if ($apiAction === self::API_ACTION_SHOW) {
            $query = $this->applyShowSingleResource($id, $mainResourceModel, $query);
        } elseif ($apiAction === self::API_ACTION_INDEX) {
            $query = $this->applySortingMainResource($request['sorting'], $query);
            $query = $this->applyFiltersAllResource($request['filters'], $mainResourceName, $mainResourceModel, $query);
        }

        return $query;
    }

    /**
     * @param mixed   $sort
     * @param         $sortableBy
     * @param array   $request
     * @param string  $mainResourceName
     *
     * @return array
     */
    private function extractSortingParameters($sort, $sortableBy, array $request, string $mainResourceName): array
    {
        if (!empty($sort) && is_string($sort)) {
            $sortParameters = explode(',', $sort);
            foreach ($sortParameters as $sortParameter) {
                $orderTypeFlag = substr($sortParameter, 0, 1);
                switch ($orderTypeFlag) {
                    case '+':
                        $sortType = 'ASC';
                        $sortingBy = substr($sortParameter, 1);
                    break;
                    case '-':
                        $sortType = 'DESC';
                        $sortingBy = substr($sortParameter, 1);
                    break;
                    default:
                        $sortType = 'ASC';
                        $sortingBy = $sortParameter;
                }
                if (in_array($sortingBy, $sortableBy)) {
                    $request['sorting'][] = ['sort_by' => $sortingBy, 'sort_type' => $sortType];
                } else {
                    $request['errors'][] = 'Sorting by ' . $sortingBy . ' is not allowed for ' . $mainResourceName;
                }
            }
        }

        return $request;
    }

    /**
     * @param mixed $limit
     * @param array $request
     *
     * @return array
     */
    private function extractLimitParameters($limit, array $request): array
    {
        if (!empty($limit) && is_array($limit)) {
            foreach ($limit as $selectedResource => $limit) {
                if (array_key_exists($selectedResource, $request['selected_fields'])) {
                    $request['limits'][$selectedResource] = intval($limit);
                } else {
                    $request['errors'][] = 'Cannot limit by unselected resource ' . $selectedResource;
                }
            }
        }

        return $request;
    }

    /**
     * @param array|null $selectedFields
     * @param array      $request
     * @param string     $mainResourceModel
     * @param string     $mainResourceName
     * @param            $selectable
     *
     * @return array
     */
    private function extractSelectedFields(
        ?array $selectedFields,
        array $request,
        string $mainResourceModel,
        string $mainResourceName,
        $selectable
    ): array {
        if (!empty($selectedFields) && is_array($selectedFields)) {
            // Remove non-comma delimited arrays from the fields
            $selectedFields = array_filter(
                $selectedFields,
                function ($item) {
                    if (empty($item)) {
                        return false;
                    }

                    return true;
                }
            );

            // Convert comma-delimited array to PHP array
            $selectedFields = array_map(
                function ($item) {
                    return explode(',', $item);
                },
                $selectedFields
            );

            // Filter by selectable fields
            foreach ($selectedFields as $selectedModel => $fields) {
                $selectedModelClass = $this->getModelClass($mainResourceModel, $selectedModel);
                if (class_exists($selectedModelClass)) {
                    if (in_array(self::ALL_FIELDS_SELECTOR, $fields)) {
                        $selectedFields[$selectedModel] = constant($selectedModelClass . '::CAN_SELECT');
                    } else {
                        $selectedFields[$selectedModel] = array_filter(
                            $fields,
                            function ($item) use ($selectedModelClass, $selectedModel, &$request) {
                                $isInArray = in_array($item, constant($selectedModelClass . '::CAN_SELECT'));

                                if (!$isInArray) {
                                    $request['errors'][] = 'There is no field ' . $item . ' on resource ' . $selectedModel;
                                }

                                return $isInArray;
                            }
                        );
                    }
                } else {
                    $request['errors'][] = 'There is no resource ' . $selectedModel;
                    unset($selectedFields[$selectedModel]);
                }
            }
        } else {
            $selectedFields = [];
        }

        // Default selected fields on the main resource are applied if there were no selected fields
        if (!array_key_exists($mainResourceName, $selectedFields)) {
            $selectedFields[$mainResourceName] = $selectable;
        }

        $request['selected_fields'] = $selectedFields;

        return $request;
    }

    /**
     * @param array|null $requestFilters
     *
     * @return array
     */
    private function extractFilters(
        ?array $requestFilters
    ): array {
        $extractedFilters = [];
        if (!empty($requestFilters)) {
            foreach ($requestFilters as $filteredResource => $filters) {
                if (!is_array($filters)) {
                    $filters = [$filters];
                }
                foreach ($filters as $filter) {
                    $matches = null;
                    $isAMatch = preg_match(self::FILTER_PATTERN, urldecode($filter), $matches);

                    if ($isAMatch) {
                        if (!isset($extractedFilters[$filteredResource])) {
                            $extractedFilters[$filteredResource] = [];
                        }
                        if (count($matches) === 4) {
                            // eg. [id][=][3]
                            $extractedFilters[$filteredResource][] = [$matches[1], $matches[2], $matches[3]];
                        } elseif (count($matches) === 3) {
                            // eg. [id] [not null]
                            $extractedFilters[$filteredResource][] = [$matches[1], $matches[2]];
                        }
                    }
                }
            }
        }

        return $extractedFilters;
    }

    private function extractSelectedFieldsFromFilters(array $request): array
    {
        $filters = $request['filters'];
        $selectedFields = $request['selected_fields'];
        foreach ($filters as $filteredResource => $filters) {
            if (!isset($selectedFields[$filteredResource])) {
                $selectedFields[$filteredResource] = [];
            }
            foreach ($filters as $filter) {
                if (array_search($filter[0], $selectedFields[$filteredResource]) === false) {
                    $selectedFields[$filteredResource] [] = $filter[0];
                }
            }
        }

        $request['selected_fields'] = $selectedFields;

        return $request;
    }

    /**
     * @param array  $request
     * @param string $mainResourceName
     * @param string $mainResourceModel
     *
     * @return array
     */
    private function extractEagerLoadedResources(
        array $request,
        string $mainResourceName,
        string $mainResourceModel
    ): array {
        $selectedFields = $request['selected_fields'];
        foreach ($selectedFields as $selectedResource => $selectedFieldList) {
            if ($selectedResource !== $mainResourceName) {
                $mainResourceModelInstance = new $mainResourceModel();
                if (method_exists($mainResourceModelInstance, $selectedResource)) {
                    if (get_class($mainResourceModelInstance->$selectedResource()) === BelongsTo::class) {
                        // The column designated as the id of the model that should be eager loaded
                        // should be selected in the main query
                        $selectedResourceRelationshipId = $selectedResource . '_id';
                        if (!in_array($selectedResourceRelationshipId, $selectedFields[$mainResourceName])) {
                            $selectedFields[$mainResourceName] [] = $selectedResourceRelationshipId;
                        }
                        // The id must be present in column list of eager loaded models
                        if (!in_array('id', $selectedFieldList)) {
                            $selectedFields[$selectedResource] [] = 'id';
                        }
                    } elseif (get_class($mainResourceModelInstance->$selectedResource()) === HasMany::class) {
                        if (!in_array($mainResourceName . '_id', $selectedFieldList)) {
                            $selectedFields[$selectedResource] [] = $mainResourceName . '_id';
                        }
                    } elseif (get_class($mainResourceModelInstance->$selectedResource()) === BelongsToMany::class) {
                        $idPosition = array_search('id', $selectedFieldList);
                        if ($idPosition !== false) {
                            // We need to specify the resource of the id field or it will be ambigous
                            unset($selectedFields[$selectedResource][$idPosition]);
                            $selectedFields[$selectedResource] [] = $selectedResource . '.id';

                            // Reset array keys after removing the id element
                            $selectedFields[$selectedResource] = array_values($selectedFields[$selectedResource]);
                        }

                        // We must select the id of the main resource
                        if (array_search('id', $selectedFields[$mainResourceName]) === false) {
                            $selectedFields[$mainResourceName][] = 'id';
                        }
                    }
                } else {
                    $request['errors'][] = 'There is no relationship between this resource, ' . $mainResourceName . ', and resource ' . $selectedResource;
                    unset($selectedFields[$selectedResource]);
                }
            }
        }

        $request['selected_fields'] = $selectedFields;

        return $request;
    }

    /**
     * @param array   $selectedFields
     * @param array   $limits
     * @param string  $mainResourceName
     * @param Builder $query
     *
     * @return Builder
     */
    private function selectEagerLoadedResources(
        array $selectedFields,
        array $limits,
        string $mainResourceName,
        Builder $query
    ): Builder {
        foreach ($selectedFields as $selectedResource => $selectedFieldList) {
            if ($selectedResource !== $mainResourceName) {
                $limit = null;
                if (isset($limits[$selectedResource])) {
                    $limit = $limits[$selectedResource];
                }
                $query->with(
                    [
                        $selectedResource => function ($query) use (
                            $selectedFieldList,
                            $limit
                        ) {
                            $query = $query->select($selectedFieldList)
                                           ->limit($limit);

                            return $query;
                        },
                    ]
                );
            }
        }

        return $query;
    }

    /**
     * @param Builder $query
     * @param array   $request
     * @param string  $mainResourceName
     *
     * @return Builder
     */
    private function selectMainResource(Builder $query, array $request, string $mainResourceName): Builder
    {
        // Selecting required fields (excepting the eager loaded relationships)
        $query = $query->select($request['selected_fields'][$mainResourceName]);

        return $query;
    }

    /**
     * @param string  $id
     * @param string  $mainResourceModel
     * @param Builder $query
     *
     * @return Builder
     */
    private function applyShowSingleResource(string $id, string $mainResourceModel, Builder $query): Builder
    {
        // Check if the main model allows single model retrieval by other means than the id
        if (defined($mainResourceModel . '::OTHER_SINGLE_IDENTIFIER')) {
            $otherIdentifier = constant($mainResourceModel . '::OTHER_SINGLE_IDENTIFIER');
            $query->where(
                function ($query) use ($id, $otherIdentifier) {
                    $query->where('id', '=', $id)
                          ->orWhere($otherIdentifier, '=', $id);
                }
            );
        } else {
            // Limiting to only one resource entity
            $query->find($id);
        }

        return $query;
    }

    /**
     * @param array   $sortItems
     * @param Builder $query
     *
     * @return Builder
     */
    private function applySortingMainResource(array $sortItems, Builder $query): Builder
    {
        foreach ($sortItems as $sortItem) {
            $query->orderBy($sortItem['sort_by'], $sortItem['sort_type']);
        }

        return $query;
    }

    /**
     * @param array   $filters
     * @param string  $mainResourceName
     * @param string  $mainResourceModel
     * @param Builder $query
     *
     * @return Builder
     */
    private function applyFiltersAllResource(
        array $filters,
        string $mainResourceName,
        string $mainResourceModel,
        Builder $query
    ): Builder {
        foreach ($filters as $filteredResource => $filters) {
            foreach ($filters as $filter) {
                if ($mainResourceName === $filteredResource) {
                    if (count($filter) === 3) {
                        // eg. id=3
                        $query = $query->where($filter[0], $filter[1], $filter[2]);
                    } elseif (count($filter) === 2) {
                        // eg. id not null
                        $query = $query->where($filter[0], $filter[1]);
                    }
                } else {
                    $filteredResourceModel = $this->getModelClass($mainResourceModel, $filteredResource);
                    $filteredResourceModelInstance = new $filteredResourceModel();
                    $table = $filteredResourceModelInstance->getTable();

                    $query = $query->whereHas(
                        $filteredResource,
                        function (Builder $query) use ($filter) {
                            if (count($filter) === 3) {
                                // eg. id=3
                                $query = $query->where($filter[0], $filter[1], $filter[2]);
                            } elseif (count($filter) === 2) {
                                // eg. id not null
                                $query = $query->where($filter[0], $filter[1]);
                            }

                            return $query;
                        }
                    );
                }
            }
        }

        return $query;
    }

    /**
     * @param string $mainResourceModel
     * @param        $selectedModel
     *
     * @return string
     */
    private function getModelClass(string $mainResourceModel, $selectedModel): string
    {
        $selectedModel = strtolower($selectedModel);
        $selectedModelParts = explode('_', $selectedModel);
        $selectedModelParts = array_map([Str::class, 'singular'], $selectedModelParts);
        $selectedModelParts = array_map('ucwords', $selectedModelParts);
        $selectedModel = implode('', $selectedModelParts);

        return substr($mainResourceModel, 0, strrpos($mainResourceModel, '\\')) . '\\' . $selectedModel;
    }
}
