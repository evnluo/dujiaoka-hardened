<?php

use Illuminate\Support\Facades\Route;

Route::get('pay-gateway/{handle}/{payway}/{orderSN}', 'PayController@redirectGateway');
Route::group(['prefix' => 'pay', 'namespace' => 'Pay'], function () {
    Route::get('yipay/{payway}/{orderSN}', 'YipayController@gateway');
    Route::match(['get', 'post'], 'yipay/notify_url', 'YipayController@notifyUrl');
    Route::get('yipay/return_url', 'YipayController@returnUrl')->name('yipay-return');
});
