<div
    id="squarePaymentForm"
    class="payment-form"
    data-application-id="<?= $paymentMethod->getAppId() ?>"
    data-location-id="<?= $paymentMethod->getLocationId() ?>"
    data-order-total="<?= Cart::total() ?>"
    data-currency-code="<?= currency()->getUserCurrency() ?>"
    data-error-selector="#square-card-errors"
    data-trigger="[type=radio][name=payment]"
    data-trigger-action="show"
    data-trigger-condition="value[square]"
    data-trigger-closest-parent="form"
>
    <div class="square-ccbox mt-3">
        <?php foreach ($paymentMethod->getHiddenFields() as $name => $value) { ?>
            <input type="hidden" name="<?= $name; ?>" value="<?= $value; ?>"/>
        <?php } ?>

        <div class="form-group">
            <div id="sq-card-number"></div>
            <div class="row no-gutters mt-1">
                <div class="col-sm-4 pr-1">
                    <div id="sq-expiration-date"></div>
                </div>
                <div class="col-sm-4 pr-1">
                    <div id="sq-cvv"></div>
                </div>
                <div class="col-sm-4">
                    <div id="sq-postal-code"></div>
                </div>
            </div>
            <div id="square-card-errors" class="text-danger"></div>
        </div>
    </div>
</div>