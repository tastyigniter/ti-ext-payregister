<?php

use Igniter\PayRegister\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('payments') || DB::table('payments')->count()) {
            return;
        }

        rescue(fn() => Payment::syncAll());
    }
};
