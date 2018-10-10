Payment system for TastyIgniter. Allows you to accept credit card payments 
using payment gateway supplied by this extension or others.

A standardized way to add online payments using [Omnipay](https://omnipay.thephpleague.com/)

#### Available Payment Gateways:
- Authorize Net AIM
- Cash On Delivery
- PayPal Express
- Stripe

**Coming soon**
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

### License
[The MIT License (MIT)](https://tastyigniter.com/licence/)