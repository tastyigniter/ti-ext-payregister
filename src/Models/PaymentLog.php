<?php

namespace Igniter\PayRegister\Models;

use Carbon\Carbon;
use Igniter\Cart\Events\OrderBeforeRefundProcessedEvent;
use Igniter\Cart\Events\OrderRefundProcessedEvent;
use Igniter\Flame\Database\Model;
use Igniter\Flame\Database\Traits\Validation;

/**
 * PaymentLog Model Class
 */
class PaymentLog extends Model
{
    use Validation;

    /**
     * @var string The database table name
     */
    protected $table = 'payment_logs';

    /**
     * @var string The database table primary key
     */
    protected $primaryKey = 'payment_log_id';

    protected $appends = ['date_added_since'];

    public $timestamps = true;

    public $dates = ['refunded_at'];

    public $relation = [
        'belongsTo' => [
            'order' => [\Igniter\Cart\Models\Order::class],
            'payment_method' => [\Igniter\PayRegister\Models\Payment::class, 'foreignKey' => 'payment_code', 'otherKey' => 'code'],
        ],
    ];

    public $rules = [
        'message' => 'string',
        'order_id' => 'integer',
        'payment_code' => 'string',
        'payment_name' => 'string',
        'is_success' => 'boolean',
        'request' => 'array',
        'response' => 'array',
        'is_refundable' => 'boolean',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'request' => 'array',
        'response' => 'array',
        'is_success' => 'boolean',
        'is_refundable' => 'boolean',
    ];

    public static function logAttempt($order, $message, $isSuccess, $request = [], $response = [], $isRefundable = false)
    {
        $record = new static;
        $record->message = $message;
        $record->order_id = $order->order_id;
        $record->payment_code = $order->payment_method->code;
        $record->payment_name = $order->payment_method->name;
        $record->is_success = $isSuccess;
        $record->request = $request;
        $record->response = $response;
        $record->is_refundable = $isRefundable;

        $record->save();
    }

    public function getDateAddedSinceAttribute($value)
    {
        return $this->created_at ? time_elapsed($this->created_at) : null;
    }

    public function markAsRefundProcessed()
    {
        if (is_null($this->refunded_at)) {
            OrderBeforeRefundProcessedEvent::dispatch($this);

            $this->refunded_at = Carbon::now();
            $this->save();

            OrderRefundProcessedEvent::dispatch($this);
        }

        return true;
    }
}
