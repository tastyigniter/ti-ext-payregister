<div
    id="squarePaymentForm"
    class="payment-form"
    data-application-id="<?= $paymentMethod->getAppId() ?>"
    data-location-id="<?= $paymentMethod->getLocationId() ?>"
    data-order-total="<?= Cart::total() ?>"
    data-currency-code="<?= currency()->getUserCurrency() ?>"
    data-error-selector="#square-card-errors"
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
            <div class="square-ccbox">
                <div id="sq-card"></div>
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
            </div>
        <?php } ?>
    </div>
</div>