<?php

declare(strict_types=1);

namespace Igniter\PayRegister\Tests\Models\Casts;

use Igniter\PayRegister\Models\Casts\EncryptedJsonArray;
use Igniter\PayRegister\Models\Payment;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

it('encodes array as encrypted json string literal', function(): void {
    $encoded = EncryptedJsonArray::encode(['secret_key' => 'sk_test_123']);

    expect(json_decode($encoded, true))->toBeString()
        ->and($encoded)->not->toContain('sk_test_123')
        ->and(EncryptedJsonArray::isEncrypted($encoded))->toBeTrue()
        ->and(EncryptedJsonArray::decode($encoded))->toBe(['secret_key' => 'sk_test_123']);
});

it('encodes null as empty array', function(): void {
    expect(EncryptedJsonArray::decode(EncryptedJsonArray::encode(null)))->toBe([]);
});

it('decodes legacy plaintext json arrays', function(): void {
    expect(EncryptedJsonArray::isEncrypted('{"secret_key":"sk_test_123"}'))->toBeFalse()
        ->and(EncryptedJsonArray::decode('{"secret_key":"sk_test_123"}'))->toBe(['secret_key' => 'sk_test_123'])
        ->and(EncryptedJsonArray::decode('[]'))->toBe([])
        ->and(EncryptedJsonArray::decode(null))->toBe([])
        ->and(EncryptedJsonArray::decode(''))->toBe([]);
});

it('throws when ciphertext cannot be decrypted', function(): void {
    expect(fn(): array => EncryptedJsonArray::decode(json_encode('not-a-valid-payload')))
        ->toThrow(DecryptException::class);
});

it('stores payment data encrypted at rest', function(): void {
    $payment = Payment::factory()->create(['data' => ['live_secret_key' => 'sk_live_secret']]);

    $raw = DB::table('payments')->where('payment_id', $payment->payment_id)->value('data');

    expect($raw)->not->toContain('sk_live_secret')
        ->and(EncryptedJsonArray::isEncrypted($raw))->toBeTrue()
        ->and(Payment::find($payment->payment_id)->data)->toBe(['live_secret_key' => 'sk_live_secret']);
});

it('encrypts gateway config fields saved through the observer', function(): void {
    $payment = Payment::factory()->create();
    $payment->applyGatewayClass();
    $payment->test_field = 'sk_test_secret';
    $payment->save();

    $raw = DB::table('payments')->where('payment_id', $payment->payment_id)->value('data');

    expect($raw)->not->toContain('sk_test_secret')
        ->and(Payment::find($payment->payment_id)->test_field)->toBe('sk_test_secret');
});

it('reads legacy plaintext payment data and encrypts it on save', function(): void {
    $payment = Payment::factory()->create();
    DB::table('payments')->where('payment_id', $payment->payment_id)
        ->update(['data' => json_encode(['test_field' => 'sk_test_legacy'])]);

    $payment = Payment::find($payment->payment_id);
    expect($payment->test_field)->toBe('sk_test_legacy');

    $payment->save();

    $raw = DB::table('payments')->where('payment_id', $payment->payment_id)->value('data');
    expect($raw)->not->toContain('sk_test_legacy')
        ->and(Payment::find($payment->payment_id)->test_field)->toBe('sk_test_legacy');
});
