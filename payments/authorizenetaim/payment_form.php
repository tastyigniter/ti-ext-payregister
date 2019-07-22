<div
    id="authorizeNetAimPaymentForm"
    class="wrap-horizontal"
    data-client-key="<?= $paymentMethod->getClientKey() ?>"
    data-api-login-id="<?= $paymentMethod->getApiLoginID() ?>"
    data-trigger="[type=radio][name=payment]"
    data-trigger-action="show"
    data-trigger-condition="value[authorizenetaim]"
    data-trigger-closest-parent="form"
>
    <div class="payment-card-icons py-2">
        <i class="fab fa-cc-visa"></i>
        <i class="fab fa-cc-mastercard"></i>
        <i class="fab fa-cc-amex"></i>
        <i class="fab fa-cc-diners-club"></i>
        <i class="fab fa-cc-jcb"></i>
    </div>

    <?php foreach ($paymentMethod->getHiddenFields() as $name => $value) { ?>
        <input type="hidden" name="<?= $name; ?>" value="<?= $value; ?>"/>
    <?php } ?>

    <div class="form-group">
        <label
            for="input-card-number"><?= lang('igniter.payregister::default.authorize_net_aim.label_card_number'); ?></label>
        <div class="input-group">
            <input
                type="text"
                id="authorizenetaim-card-number"
                class="form-control"
                value=""
                placeholder="<?= lang('igniter.payregister::default.authorize_net_aim.text_cc_number'); ?>"
                autocomplete="off"
                required
            />
            <span class="input-group-prepend">
                <span class="input-group-text"><i class="fa fa-credit-card"></i></span>
            </span>
        </div>
    </div>
    <div class="row">
        <div class="col-xs-7 col-md-7">
            <div class="form-group">
                <label
                    for="input-expiry-month"><?= lang('igniter.payregister::default.authorize_net_aim.label_card_expiry'); ?></label>
                <div class="row">
                    <div class="col-xs-6 col-lg-6">
                        <input
                            type="text"
                            class="form-control"
                            id="authorizenetaim-expiry-month"
                            value=""
                            placeholder="<?= lang('igniter.payregister::default.authorize_net_aim.text_exp_month'); ?>"
                            autocomplete="off"
                            required
                        />
                    </div>
                    <div class="col-xs-6 col-lg-6">
                        <input
                            type="text"
                            class="form-control"
                            id="authorizenetaim-expiry-year"
                            value=""
                            placeholder="<?= lang('igniter.payregister::default.authorize_net_aim.text_exp_year'); ?>"
                            autocomplete="off"
                            required
                        />
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xs-5 col-md-5 pull-right">
            <div class="form-group">
                <label
                    for="input-card-cvc"><?= lang('igniter.payregister::default.authorize_net_aim.label_card_cvc'); ?></label>
                <input
                    type="text"
                    class="form-control"
                    id="authorizenetaim-card-cvc"
                    value=""
                    placeholder="<?= lang('igniter.payregister::default.authorize_net_aim.text_cc_cvc'); ?>"
                    autocomplete="off"
                    required
                />
            </div>
        </div>
    </div>
    <div class="form-group">
        <input
            type="text"
            class="form-control"
            id="authorizenetaim-postcode"
            value=""
            placeholder="<?= lang('igniter.cart::default.checkout.label_postcode'); ?>"
        />
    </div>

    <div id="authorizenetaim-errors" class="text-danger"></div>
</div>