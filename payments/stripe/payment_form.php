<div
    id="stripePaymentForm"
    class="payment-form w-100"
    data-publishable-key="<?= $paymentMethod->getPublishableKey() ?>"
    data-card-selector="#stripe-card-element"
    data-error-selector="#stripe-card-errors"
>
    <?php foreach ($paymentMethod->getHiddenFields() as $name => $value) { ?>
        <input type="hidden" name="<?= $name; ?>" value="<?= $value; ?>"/>
    <?php } ?>

    <div class="form-group">
        <?php if ($paymentProfile = $paymentMethod->findPaymentProfile($order->customer)) { ?>
            <input type="hidden" name="pay_from_profile" value="1">
            <div>
                <i class="fab fa-fw fa-cc-<?= $paymentProfile->card_brand ?>"></i>&nbsp;&nbsp;
                <b>&bull;&bull;&bull;&bull;&nbsp;&bull;&bull;&bull;&bull;&nbsp;&bull;&bull;&bull;&bull;&nbsp;<?= $paymentProfile->card_last4 ?></b>
                &nbsp;&nbsp;-&nbsp;&nbsp;
                <a
                    class="text-danger"
                    href="javascript:;"
                    data-checkout-control="delete-payment-profile"
                    data-payment-code="<?= $paymentMethod->code ?>"
                ><?= lang('igniter.payregister::default.button_delete_card') ?></a>
            </div>
        <?php } else { ?>
            <label
                for="stripe-card-element"
            ><?= lang('igniter.payregister::default.stripe.text_credit_or_debit') ?></label>
            <div id="stripe-card-element">
                <!-- A Stripe Element will be inserted here. -->
            </div>

            <!-- Used to display form errors. -->
            <div id="stripe-card-errors" class="text-danger" role="alert"></div>

            <?php if ($paymentMethod->supportsPaymentProfiles() AND $order->customer) { ?>
                <div class="custom-control custom-checkbox mt-2">
                    <input
                        id="save-customer-profile"
                        type="checkbox"
                        class="custom-control-input"
                        name="create_payment_profile"
                        value="1"
                    >
                    <label
                        class="custom-control-label"
                        for="save-customer-profile"
                    ><?= lang('igniter.payregister::default.text_save_card_profile'); ?></label>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>