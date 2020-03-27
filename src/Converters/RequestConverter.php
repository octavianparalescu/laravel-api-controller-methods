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

    /**
     * Converts the HTTP Request parameters to a Model Query for the
     * index Unauthenticated controller
     *
     * @param string  $selectedModelClass
     * @param Request $httpRequest
     *
     * @return Builder
     */
    public function convert(string $mainResourceModel, Request $httpRequest, string $apiAction, string $id = null): Builder
    {
        $sortableBy = constant($mainResourceModel . '::SORTABLE_BY');
        $selectable = constant($mainResourceModel . '::CAN_SELECT');

        /** @var Builder $query */
        $query = call_user_func([$mainResourceModel, 'query']);

        $mainResourceName = Str::singular($query->getModel()->getTable());

        // Sorting
        if ($apiAction === self::API_ACTION_INDEX) {
            $query = $this->applySorting($httpRequest, $sortableBy, $query);
        }

        // Process query fields
        $selectedFields = $this->processQueryFields($mainResourceModel, $httpRequest, $mainResourceName, $selectable);

        // Eager loading
        $selectedFields = $this->eagerLoadRelatedResources($selectedFields, $mainResourceName, $query, $mainResourceModel);

        // Selecting required fields (excepting the eager loaded relationships)
        $query->select($selectedFields[$mainResourceName]);

        // Limiting to only one resource entity
        if ($apiAction === self::API_ACTION_SHOW) {
            $query->find($id);
        }

        return $query;
    }

    /**
     * @param Request $httpRequest
     * @param array   $sortableBy
     * @param Builder $query
     *
     * @return Builder
     */
    private function applySorting(Request $httpRequest, array $sortableBy, Builder $query): Builder
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

        return $query;
    }

    /**
     * @param string $selectedModelClass
     * @param        $httpRequest
     * @param string $mainResourceName
     * @param        $selectable
     *
     * @return array
     */
    private function processQueryFields(string $mainResourceModel, $httpRequest, string $mainResourceName, $selectable): array
    {
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
                $selectedModelClass = $this->getModelClass($mainResourceModel, $selectedModel);
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
    private function eagerLoadRelatedResources(
        array $selectedFields,
        string $mainResourceName,
        Builder $query,
        string $mainResourceModel
    ): array {
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
                            $selectedFieldList [] = 'id';
                        }
                    } elseif (get_class($mainResourceModelInstance->$selectedResource()) === HasMany::class) {
                        if (!in_array($mainResourceName . '_id', $selectedFieldList)) {
                            $selectedFieldList [] = $mainResourceName . '_id';
                        }
                    } elseif (get_class($mainResourceModelInstance->$selectedResource()) === BelongsToMany::class) {
                        $idPosition = array_search('id', $selectedFieldList);
                        if ($idPosition !== false) {
                            unset($selectedFieldList[$idPosition]);
                            $selectedFieldList [] = $selectedResource . '.id';
                        }
                    }
                    $relations = $selectedResource . ':' . implode(',', $selectedFieldList);
                    $query->with($relations);
                }
            }
        }

        return $selectedFields;
    }

    /**
     * @param string $mainResourceModel
     * @param        $selectedModel
     *
     * @return string
     */
    private function getModelClass(string $mainResourceModel, $selectedModel): string
    {
        return substr($mainResourceModel, 0, strrpos($mainResourceModel, '\\')) . '\\' . ucwords(
                Str::singular(strtolower($selectedModel))
            );
    }
}
