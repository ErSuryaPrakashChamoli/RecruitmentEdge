<?php

use Illuminate\Support\Facades\Route;
//stage 1
Route::get('/', function () {
    return view('welcome');
});

