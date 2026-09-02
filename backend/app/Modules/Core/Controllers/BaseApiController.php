<?php

declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Traits\ApiResponse;

abstract class BaseApiController extends Controller
{
    use ApiResponse;
}
