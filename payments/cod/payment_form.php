<div
    class="radio"
>
    <label>
        <?php if (!$paymentMethod->isApplicable($order->order_total, $paymentMethod)) { ?>
            <input type="radio" name="payment" value="" disabled/>
        <?php } else { ?>
            <input
                type="radio"
                name="payment"
                value="cod"
            />
        <?php } ?>
        <?= $paymentMethod->name; ?>
    </label>
    <?php if (!$paymentMethod->isApplicable($order->order_total, $paymentMethod)) { ?>
        <span class="text-info"><?= sprintf(
                lang('igniter.payregister::default.alert_min_order_total'),
                currency_format($paymentMethod->order_total),
                $paymentMethod->name
            ); ?></span>
    <?php } ?>
</div>