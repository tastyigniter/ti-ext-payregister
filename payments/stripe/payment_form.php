<div
    id="stripePaymentForm"
    class="payment-form hide w-100"
    data-publishable-key="<?= $paymentMethod->getPublishableKey() ?>"
    data-card-selector="#stripe-card-element"
    data-error-selector="#stripe-card-errors"
    data-trigger="[type=radio][name=payment]"
    data-trigger-action="show"
    data-trigger-condition="value[stripe]"
    data-trigger-closest-parent="form"
>
    <?php foreach ($paymentMethod->getHiddenFields() as $name => $value) { ?>
        <input type="hidden" name="<?= $name; ?>" value="<?= $value; ?>"/>
    <?php } ?>

    <div class="form-group mt-2">
        <label for="stripe-card-element">
            Credit or debit card
        </label>
        <div id="stripe-card-element">
            <!-- A Stripe Element will be inserted here. -->
        </div>

        <!-- Used to display form errors. -->
        <div id="stripe-card-errors" class="text-danger" role="alert"></div>
    </div>
</div>