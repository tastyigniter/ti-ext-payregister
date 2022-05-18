<?php

namespace Igniter\PayRegister\Database\Migrations;

use Igniter\Admin\Models\Payment;
use Igniter\Main\Classes\ThemeManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (!Schema::hasTable('payments') || DB::table('payments')->count())
            return;

        if (!ThemeManager::instance()->getActiveTheme())
            return;

        Payment::syncAll();
    }
};
