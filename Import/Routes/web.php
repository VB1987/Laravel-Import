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

Route::prefix('import')->group(function() {
    Route::get('/', 'ImportController@index')->name('import.index');
    Route::get('/{media?}/{page?}', 'ImportController@import')->name('import.import');
    Route::get('/excel/headers/{media?}', 'ImportController@getExcelHeaders')->name('import.excel.headers');
    Route::post('files/upload', 'ImportController@addFiles')->name('files.upload');
});
