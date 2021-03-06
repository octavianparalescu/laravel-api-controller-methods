# laravel-api-controller-methods
Laravel Controller methods that allow the developer to quickly build a read-only Resource-based API.

Particularly useful when creating read-only APIs for statical websites or for infrequent-modified data.

Requirements:

| Item | Version |
| ---- | ------- |
| PHP  | 7.1+    |
| Laravel | 7+ |

## Usage
Installing using composer:

`composer require octavianparalescu/laravel-api-controller-methods`

After creating the model, the controller and defining the controller as a resource-controller, load
 the Request Converter as a dependency,
load the traits into your controller and use the traits' methods:

```php
<?php
// app/Http/Controllers/CityController.php

namespace App\Http\Controllers;

use App\City;
use OctavianParalescu\ApiController\Controllers\ApiIndexTrait;
use OctavianParalescu\ApiController\Controllers\ApiShowTrait;
use OctavianParalescu\ApiController\Converters\RequestConverter;

class CityController extends Controller
{
    use ApiIndexTrait, ApiShowTrait;
    const CAN_SELECT = ['id', 'name']; // Fields that can be selected
    const OTHER_SINGLE_IDENTIFIER = 'name'; // If you have a different primary key than "id"
    const FILTERABLE_BY = ['name']; // If empty, resource is not filterable at all
    /**
     * @var RequestConverter
     */
    private $requestConverter;

    public function __construct(RequestConverter $requestConverter)
    {
        $this->requestConverter = $requestConverter;
    }

    public function index()
    {
        return $this->apiIndex($this->requestConverter, City::class);
    }

    public function show($id)
    {
        return $this->apiShow($this->requestConverter, City::class, $id);
    }
}
```

To use the controller as a resource-controller:

```php
<?php
// routes/web.php

use Illuminate\Support\Facades\Route;

Route::resource('city', 'CityController', ['only' => ['index', 'show']]);
```

To be able to do cross-origin requests, I recommend this middleware:
https://gist.github.com/octavianparalescu/b49af1b859f7850b0d7de426ab989b0b

To pretty-print the json response by default I recommend this middleware:
https://gist.github.com/octavianparalescu/22859ceadff3cda8d278663d9835dd88

## Model options

You can use the CAN_SELECT and SORTABLE_BY class constants to define settings in the model:
```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class County extends Model
{
    const CAN_SELECT = ['id', 'name', 'type', 'created_at'];
    const SORTABLE_BY = ['id', 'name'];
}
```

You can define relationships in the models, relationships that are then accessible using the generated API:
```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CitySection extends Pivot
{

    /**
     * This pivot table refers to an entity that has multiple Item entities
     * as children
     * @return HasMany
     */
    public function items()
    {
        return $this->hasMany(Item::class, 'city_section_id', 'id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }
}
```

Sample usage:
```html
http://localhost/item/1?select[city_section]=id
http://localhost/city_section/2?fields[city]=name&fields[section]=name&fields[items]=city_section_id,name
http://localhost/city/25291?fields[county]=name&fields[sections]=id,name,description
http://localhost/city?fields[sections]=id,name&per_page=3186
http://localhost/city?fields[sections]=id,name&per_page=3186&filters[county][]=name%20=%20Bac%C4%83u&filters[county][]=id=47
http://localhost/city?fields[sections]=id,name&per_page=3186&filters[county]=name%20=%20Bac%C4%83u
http://localhost/city?fields[city]=name,plm&fields[items]=id&fields[kkt]=2&fields[county]=name&fields[sections]=id,name,description&limit[sections]=2&filters[city]=name%20LIKE%20Scor%C8%9Beni
```

Model and corresponding tables must follow the official Laravel naming convention.
## ToDo:
- tag versions
- tests