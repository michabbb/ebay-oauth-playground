<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', 'ebayOauthLogin@welcome');
Route::get('/logout', 'ebayOauthLogin@logout');
Route::get('/refresh', 'ebayOauthLogin@refreshtoken');
Route::get('/checktoken/{userid}', 'ebayOauthLogin@checktoken');

