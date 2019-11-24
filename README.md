Payment system for TastyIgniter. Allows you to accept credit card payments 
using payment gateway supplied by this extension or others.

A standardized way to add online payments using [Omnipay](https://omnipay.thephpleague.com/)

#### Available Payment Gateways:
- Authorize Net AIM
- Cash On Delivery
- PayPal Express
- Stripe
- Mollie
- Square

### Getting Started
Go to **Sales > Payments** to enable and manage payments.

### Registering a new Payment Gateway

Here is an example of an extension registering a payment gateway.

```
public function registerPaymentGateways()
{
    return [
        \Igniter\Local\Payments\PayPalStandard::class => [
            'code' => 'paypal_standard',
            'name' => 'PayPal Standard',
            'description' => 'Description of the payment gateway',
        ]
    ];
}
```

**Example of a Payment Gateway Class**

A payment gateway class is responsible for handing the payment method during checkout.

```
class Cod extends BasePaymentGateway
{
    /**
     * Returns true if the payment type is applicable for a specified invoice amount
     *
     */
    public function isApplicable($total, $host)
    {
        return $host->order_total <= $total;
    }

    /**
     * Processes payment using passed data.
     *
     * @param array $data
     * @param \Admin\Models\Payments_model $host
     * @param \Admin\Models\Orders_model $order
     *
     * @throws \ApplicationException
     */
    public function processPaymentForm($data, $host, $order)
    {
        if (!$paymentMethod = $order->payment)
            throw new ApplicationException('Payment method not found');

        if (!$this->isApplicable($order->order_total, $host))
            throw new ApplicationException(sprintf(
                lang('igniter.payregister::default.alert_min_order_total'),
                currency_format($host->order_total),
                $host->name
            ));

        $order->updateOrderStatus($host->order_status, ['notify' => FALSE]);
        $order->markAsPaymentProcessed();
    }
}
```

### License
[The MIT License (MIT)](https://tastyigniter.com/licence/)