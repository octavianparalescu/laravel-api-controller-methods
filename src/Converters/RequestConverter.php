<?php


namespace OctavianParalescu\ApiController\Converters;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class RequestConverter
{
    /**
     * Converts the HTTP Request parameters to a Model Query for the
     * index Unauthenticated controller
     *
     * @param string $selectedModelClass
     * @param        $httpRequest
     *
     * @return Builder
     */
    public function convert(string $selectedModelClass, $httpRequest): Builder
    {
        $sortableBy = constant($selectedModelClass . '::SORTABLE_BY');
        $selectable = constant($selectedModelClass . '::CAN_SELECT');

        /** @var Builder $query */
        $query = call_user_func([$selectedModelClass, 'query']);

        $mainResourceName = Str::singular($query->getModel()->getTable());

        // Sorting
        $this->applySorting($httpRequest, $sortableBy, $query);

        // Process query fields
        $selectedFields = $this->processQueryFields($selectedModelClass, $httpRequest, $mainResourceName, $selectable);

        // Eager loading
        $selectedFields = $this->eagerLoadRelatedResources($selectedFields, $mainResourceName, $query);

        // Selecting required fields (excepting the eager loaded relationships)
        $query->select($selectedFields[$mainResourceName]);

        return $query;
    }

    /**
     * @param         $httpRequest
     * @param         $sortableBy
     * @param Builder $query
     */
    private function applySorting($httpRequest, $sortableBy, Builder $query): void
    {
        if (!empty($httpRequest->sort)) {
            $sortParameters = explode(',', $httpRequest->sort);
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
                    $query->orderBy($sortingBy, $sortType);
                }
            }
        }
    }

    /**
     * @param string $selectedModelClass
     * @param        $httpRequest
     * @param string $mainResourceName
     * @param        $selectable
     *
     * @return array
     */
    private function processQueryFields(string $selectedModelClass, $httpRequest, string $mainResourceName, $selectable): array
    {
        $namespace = substr($selectedModelClass, 0, strrpos($selectedModelClass, '\\'));
        if (!empty($httpRequest->fields) && is_array($httpRequest->fields)) {
            $selectedFields = $httpRequest->fields;

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
                $selectedModelClass = $namespace . ucwords(strtolower($selectedModel));
                if (class_exists($selectedModelClass)) {
                    $selectedFields[$selectedModel] = array_filter(
                        $fields,
                        function ($item) use ($selectedModelClass) {
                            return in_array($item, constant($selectedModelClass . '::CAN_SELECT'));
                        }
                    );
                } else {
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

        return $selectedFields;
    }

    /**
     * @param array   $selectedFields
     * @param string  $mainResourceName
     * @param Builder $query
     *
     * @return array
     */
    private function eagerLoadRelatedResources(array $selectedFields, string $mainResourceName, Builder $query): array
    {
        foreach ($selectedFields as $selectedResource => $selectedFieldList) {
            if ($selectedResource !== $mainResourceName) {
                // The column designated as the id of the model that should be eager loaded
                // should be selected in the main query
                $selectedResourceRelationshipId = $selectedResource . '_id';
                if (!in_array($selectedResourceRelationshipId, $selectedFields[$mainResourceName])) {
                    $selectedFields[$mainResourceName] [] = $selectedResourceRelationshipId;
                }
                // The id must be present in column list of eager loaded models
                if (!in_array('id', $selectedFieldList)) {
                    $selectedFieldList [] = 'id';
                }
                $relations = $selectedResource . ':' . implode(',', $selectedFieldList);
                $query->with($relations);
            }
        }

        return $selectedFields;
    }
}
