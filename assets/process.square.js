+function ($) {
    "use strict"

    var ProcessSquare = function (element, options) {
        this.$el = $(element)
        this.options = options || {}
        this.$checkoutForm = this.$el.closest('#checkout-form')
        this.square = null

        $('[name=payment][value=square]', this.$checkoutForm).on('change', $.proxy(this.init, this))
    }

    ProcessSquare.prototype.init = function () {
        if (!$('#'+this.options.cardFields.card.elementId).length)
            return

        var spOptions = {
            applicationId: this.options.applicationId,
            locationId: this.options.locationId,
            autoBuild: false,
            inputClass: 'form-control',
            callbacks: {
                cardNonceResponseReceived: $.proxy(this.onResponseReceived, this)
            }
        }

        if (this.options.applicationId === undefined)
            throw new Error('Missing square application id')

        this.square = new SqPaymentForm($.extend(spOptions, this.options.cardFields))

        this.square.build()

        this.$checkoutForm.on('submitCheckoutForm', $.proxy(this.submitFormHandler, this))
    }

    ProcessSquare.prototype.submitFormHandler = function (event) {
        var $form = this.$checkoutForm,
            $paymentInput = $form.find('input[name="payment"]:checked')

        if ($paymentInput.val() !== 'square') return

        // Prevent the form from submitting with the default action
        event.preventDefault()

        this.square.requestCardNonce();
    }

    ProcessSquare.prototype.onResponseReceived = function (errors, nonce, cardData) {
        var self = this,
            $form = this.$checkoutForm,
            verificationDetails = {
                intent: 'CHARGE',
                amount: this.options.orderTotal.toString(),
                currencyCode: this.options.currencyCode,
                billingContact: {
                    givenName: $('input[name="first_name"]', this.$checkoutForm).val(),
                    familyName: $('input[name="last_name"]', this.$checkoutForm).val(),
                }
            }

        if (errors) {
            var $el = '<b>Encountered errors:</b>';
            errors.forEach(function (error) {
                $el += '<div>' + error.message + '</div>'
            });
            $form.find(this.options.errorSelector).html($el);
            return;
        }

        this.square.verifyBuyer(nonce, verificationDetails, function (err, response) {
            if (err == null) {
                $form.find('input[name="square_card_nonce"]').val(nonce);
                $form.find('input[name="square_card_token"]').val(response.token);

                // Switch back to default to submit form
                $form.unbind('submitCheckoutForm').submit()
            }
        });
    }

    ProcessSquare.DEFAULTS = {
        applicationId: undefined,
        locationId: undefined,
        orderTotal: undefined,
        currencyCode: undefined,
        errorSelector: '#square-card-errors',
        // Customize the CSS for SqPaymentForm iframe elements
        cardFields: {
            card: {
                elementId: 'sq-card',
                inputStyle: {
                    fontSize: '16px',
                    autoFillColor: '#000',    //Sets color of card nbr & exp. date
                    color: '#000',            //Sets color of CVV & Zip
                    placeholderColor: '#A5A5A5', //Sets placeholder text color
                    backgroundColor: '#FFF',  //Card entry background color
                    cardIconColor: '#A5A5A5', //Card Icon color
                },
            },
        }
    }

    // PLUGIN DEFINITION
    // ============================

    var old = $.fn.processSquare

    $.fn.processSquare = function (option) {
        var $this = $(this).first()
        var options = $.extend(true, {}, ProcessSquare.DEFAULTS, $this.data(), typeof option == 'object' && option)

        return new ProcessSquare($this, options)
    }

    $.fn.processSquare.Constructor = ProcessSquare

    $.fn.processSquare.noConflict = function () {
        $.fn.processSquare = old
        return this
    }

    $(document).render(function () {
        $('#squarePaymentForm').processSquare()
    })
}(window.jQuery)