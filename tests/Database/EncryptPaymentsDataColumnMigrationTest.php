<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Tests\Database;

use Igniter\PayRegister\Models\Casts\EncryptedJsonArray;
use Igniter\PayRegister\Models\Payment;
use Illuminate\Support\Facades\DB;

it('encrypts existing plaintext payment data and reverses it', function(): void {
    $migration = require __DIR__.'/../../database/migrations/2026_11_08_000100_encrypt_payments_data_column.php';

    $plain = Payment::factory()->create();
    DB::table('payments')->where('payment_id', $plain->payment_id)
        ->update(['data' => json_encode(['live_secret_key' => 'sk_live_plain'])]);

    $alreadyEncrypted = Payment::factory()->create(['data' => ['live_secret_key' => 'sk_live_encrypted']]);

    $migration->up();

    $rawPlain = DB::table('payments')->where('payment_id', $plain->payment_id)->value('data');
    $rawEncrypted = DB::table('payments')->where('payment_id', $alreadyEncrypted->payment_id)->value('data');

    expect($rawPlain)->not->toContain('sk_live_plain')
        ->and(EncryptedJsonArray::decode($rawPlain))->toBe(['live_secret_key' => 'sk_live_plain'])
        ->and(EncryptedJsonArray::decode($rawEncrypted))->toBe(['live_secret_key' => 'sk_live_encrypted']);
});
