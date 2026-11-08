<?php

declare(strict_types=1);

use Igniter\PayRegister\Models\Casts\EncryptedJsonArray;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payments')) {
            return;
        }

        DB::table('payments')->get(['payment_id', 'data'])->each(function($payment): void {
            if (EncryptedJsonArray::isEncrypted($payment->data)) {
                return;
            }

            DB::table('payments')
                ->where('payment_id', $payment->payment_id)
                ->update(['data' => EncryptedJsonArray::encode(EncryptedJsonArray::decode($payment->data))]);
        });
    }
};
