+function ($) {
    "use strict"

    var ProcessSquare = function (element, options) {
        this.$el = $(element)
        this.options = options || {}
        this.$checkoutForm = this.$el.closest('#checkout-form')
        this.square = null
        this.formLoaded = false

        if (this.options.applicationId === undefined)
            throw new Error('Missing square application id')

        this.init()
    }

    ProcessSquare.prototype.init = function () {
        var spOptions = {
            applicationId: this.options.applicationId,
            locationId: this.options.locationId,
            autoBuild: false,
            inputClass: 'form-control',
            callbacks: {
                cardNonceResponseReceived: $.proxy(this.onResponseReceived, this)
            }
        }

        this.square = new SqPaymentForm($.extend(spOptions, this.options.cardFields))

        $('[name=payment][value=square]', this.$checkoutForm).on('change', $.proxy(this.buildForm, this))
    }

    ProcessSquare.prototype.buildForm = function () {
        if (this.formLoaded || !SqPaymentForm.isSupportedBrowser())
            return

        this.square.build()

        this.$checkoutForm.on('submitCheckoutForm', $.proxy(this.submitFormHandler, this))
        this.formLoaded = true
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
        cardFields: {
            cardNumber: {
                elementId: 'sq-card-number',
                placeholder: '• • • •  • • • •  • • • •  • • • •'
            },
            cvv: {
                elementId: 'sq-cvv',
                placeholder: 'CVV'
            },
            expirationDate: {
                elementId: 'sq-expiration-date',
                placeholder: 'MM/YY'
            },
            postalCode: {
                elementId: 'sq-postal-code',
                placeholder: 'Postal'
            }
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