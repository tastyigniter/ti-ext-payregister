<?php

Route::group([
    'prefix' => 'ti_payregister',
    'middleware' => ['web'],
], function () {
    Route::any('{code}/{slug}', function ($code, $slug) {
        return \Igniter\Admin\Classes\PaymentGateways::runEntryPoint($code, $slug);
    })->where('slug', '(.*)?');
});
