<?php

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

        if (!resolve(ThemeManager::class)->getActiveTheme())
            return;

        Payment::syncAll();
    }
};