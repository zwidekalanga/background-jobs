<?php

use Illuminate\Support\Facades\Route;
use App\Jobs\ManualTestJob;

Route::get('/', function () {
    return view('welcome');
});
