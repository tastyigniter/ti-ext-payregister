+function ($) {
    "use strict"

    var AuthorizeNetAim = function (element, options) {
        this.$el = $(element)
        this.options = options || {}
        this.$checkoutForm = this.$el.closest('#checkout-form')

        if (this.options.clientKey === undefined || this.options.apiLoginId === undefined)
            throw new Error('Missing Authorize.Net client key or API Login ID')

        this.init()
    }

    AuthorizeNetAim.prototype.init = function () {
        this.$checkoutForm.on('submitCheckoutForm', $.proxy(this.submitFormHandler, this))
    }

    AuthorizeNetAim.prototype.submitFormHandler = function (event) {
        var secureData = {},
            $form = this.$checkoutForm,
            $paymentInput = $form.find('input[name="payment"]:checked'),
            $errorsEl = $form.find(this.options.errorSelector)

        if ($paymentInput.val() !== 'authorizenetaim') return

        // Prevent the form from submitting with the default action
        event.preventDefault()

        secureData.authData = {
            clientKey: this.options.clientKey,
            apiLoginID: this.options.apiLoginId
        }

        secureData.cardData = {
            cardNumber: $('#authorizenetaim-card-number').val(),
            month: $('#authorizenetaim-expiry-month').val(),
            year: $('#authorizenetaim-expiry-year').val(),
            cardCode: $('#authorizenetaim-card-cvc').val(),
            fullName: $('input[name="first_name"]').val() + ' ' + $('input[name="last_name"]').val(),
            zip: $('#authorizenetaim-postcoder').val(),
        }

        Accept.dispatchData(secureData, responseHandler);

        function responseHandler(response) {
            if (response.messages.resultCode === "Error") {
                var i = 0;
                while (i < response.messages.message.length) {
                    $errorsEl.html(response.messages.message[i].code + ": " +
                        response.messages.message[i].text)
                    i = i + 1;
                }
            } else {
                $form.find('#dataDescriptor').val(response.opaqueData.dataDescriptor)
                $form.find('#dataValue').val(response.opaqueData.dataValue)

                // Switch back to default to submit form
                $form.unbind('submitCheckoutForm').submit()
            }
        }
    }

    AuthorizeNetAim.DEFAULTS = {
        clientKey: undefined,
        apiLoginId: undefined,
        errorSelector: '#authorizenetaim-errors',
    }

    // PLUGIN DEFINITION
    // ============================

    var old = $.fn.authorizeNetAim

    $.fn.authorizeNetAim = function (option) {
        var $this = $(this).first()
        var options = $.extend(true, {}, AuthorizeNetAim.DEFAULTS, $this.data(), typeof option == 'object' && option)

        return new AuthorizeNetAim($this, options)
    }

    $.fn.authorizeNetAim.Constructor = AuthorizeNetAim

    $.fn.authorizeNetAim.noConflict = function () {
        $.fn.booking = old
        return this
    }

    $(document).render(function () {
        $('#authorizeNetAimPaymentForm').authorizeNetAim()
    })
}(window.jQuery)