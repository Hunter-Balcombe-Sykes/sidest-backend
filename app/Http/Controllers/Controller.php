<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

// V2: Base controller class. All API controllers extend this abstract class.
abstract class Controller
{
    use AuthorizesRequests;
}
