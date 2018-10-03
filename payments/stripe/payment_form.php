<div
    id="stripePaymentForm"
    class="wrap-horizontal"
    data-publishable-key="<?= $paymentMethod->getPublishableKey() ?>"
    data-card-selector="#stripe-card-element"
    data-error-selector="#stripe-card-errors"
    data-trigger="[name='payment']"
    data-trigger-action="show"
    data-trigger-condition="value[stripe]"
    data-trigger-closest-parent="form"
>
    <?php foreach ($paymentMethod->getHiddenFields() as $name => $value) { ?>
        <input type="hidden" name="<?= $name; ?>" value="<?= $value; ?>"/>
    <?php } ?>

    <div class="form-group">
        <label for="stripe-card-element">
            Credit or debit card
        </label>
        <div id="stripe-card-element">
            <!-- A Stripe Element will be inserted here. -->
        </div>

        <!-- Used to display form errors. -->
        <div id="stripe-card-errors" role="alert"></div>
    </div>
</div>