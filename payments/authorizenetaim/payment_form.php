<div
    id="authorizeNetAimPaymentForm"
    class="hide"
    data-button-selector=".AcceptUI"
    data-error-selector="#authorizenetaim-errors"
>
    <?php foreach ($paymentMethod->getHiddenFields() as $name => $value) { ?>
        <input type="hidden" name="<?= $name; ?>" value="<?= $value; ?>"/>
    <?php } ?>

    <button
        type="button"
        class="AcceptUI hide"
        data-billingAddressOptions='{"show":false, "required":false}'
        data-api-login-id="<?= $paymentMethod->getApiLoginID() ?>"
        data-client-key="<?= $paymentMethod->getClientKey() ?>"
        data-paymentOptions='{"showCreditCard": true, "showBankAccount": false}'
        data-acceptUIFormHeaderTxt="Card Information"
        data-responseHandler="responseHandler"
    ></button>

    <div id="authorizenetaim-errors" class="text-danger"></div>
</div>