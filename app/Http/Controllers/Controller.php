<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    // Laravel 13's slim base controller ships without these traits;
    // $this->authorize() / validation helpers depend on them.
    use AuthorizesRequests, ValidatesRequests;
}
