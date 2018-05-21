<?php

Route::prefix('ti_payregister')->group(function () {

    Route::any('{code}/{slug}', function ($code, $slug) {

        return \Admin\Classes\PaymentGateways::runEntryPoint($code, $slug);
    })->where('slug', '(.*)?');
});