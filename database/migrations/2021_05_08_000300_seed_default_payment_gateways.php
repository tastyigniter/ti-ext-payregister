<?php

namespace Igniter\PayRegister\Database\Migrations;

use Admin\Models\Payments_model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Main\Classes\ThemeManager;

class SeedDefaultPaymentGateways extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('payments') OR DB::table('payments')->count())
            return;

        if (!ThemeManager::instance()->getActiveTheme())
            return;

        Payments_model::syncAll();
    }
}
