+function ($) {
    "use strict"

    var ProcessStripe = function (element, options) {
        this.$el = $(element)
        this.options = options || {}
        this.$checkoutForm = this.$el.closest('#checkout-form')
        this.stripe = null
        this.card = null

        $('[name=payment][value=stripe]', this.$checkoutForm).on('change', $.proxy(this.init, this))
    }

    ProcessStripe.prototype.init = function () {
        if (this.stripe !== null || !$(this.options.cardSelector).length)
            return

        if (this.options.publishableKey === undefined)
            throw new Error('Missing stripe publishable key')

        // Create a Stripe client.
        this.stripe = Stripe(this.options.publishableKey, {
            locale: $('html').attr('lang')
        })

        // Used by Stripe to identify this integration
        this.stripe.registerAppInfo({
            name: "TastyIgniter Stripe",
            partner_id: this.options.partnerId,  // Used by Stripe to identify this integration
            url: 'https://tastyigniter.com/marketplace/item/igniter-payregister'
        });

        // Create an instance of the card Element.
        this.card = this.stripe.elements().create('card')

        // Add an instance of the card Element into the `card-element` <div>.
        this.card.mount(this.options.cardSelector);

        // Handle real-time validation errors from the card Element.
        this.card.addEventListener('change', $.proxy(this.validationErrorHandler, this))

        // set up one click payments
        this.setupPaymentButton();

        this.$checkoutForm.on('submitCheckoutForm', $.proxy(this.submitFormHandler, this))
    }

    ProcessStripe.prototype.setupPaymentButton = function () {
        var paymentRequest = this.stripe.paymentRequest({
            country: this.options.country,
            currency: this.options.currency,
            total: {
                label: 'Total',
                amount: this.options.total,
            },
            requestPayerName: false,
            requestPayerEmail: false,
        });

        var paymentRequestButton = this.stripe.elements().create('paymentRequestButton', {
            paymentRequest: paymentRequest,
        });

        // Check the availability of the Payment Request API first.
        paymentRequest.canMakePayment().then(function (result) {
            if (result) {
                paymentRequestButton.mount(this.options.paymentRequestSelector);
            } else {
                document.querySelector(this.options.paymentRequestSelector).style.display = 'none';
            }
        }.bind(this));

        var self = this;
        paymentRequest.on('paymentmethod', function (ev) {
            // run precheckout validation, and on response do stripe things
            this.$checkoutForm.data('ti.checkout').onValidateCheckoutForm(this.$checkoutForm, (validateResponse) => {
                this.stripe.confirmCardPayment(
                    clientSecret,
                    {payment_method: ev.paymentMethod.id},
                    {handleActions: false}
                ).then(function (confirmResult) {
                    console.log(confirmResult);
                    if (confirmResult.error) {
                        ev.complete('fail');
                        this.validationErrorHandler(confirmResult);
                    } else {
                        ev.complete('success');
                        if (confirmResult.paymentIntent.status === 'requires_action') {
                            this.stripe.confirmCardPayment(clientSecret).then(function (result) {
                                console.log(result);
                                if (result.error) {
                                    this.validationErrorHandler(result);
                                } else {
                                    this.$checkoutForm.unbind('submitCheckoutForm').submit()
                                }
                            });
                        } else {
                            this.$checkoutForm.unbind('submitCheckoutForm').submit()
                        }
                    }
                });
            });
        });
    }

    ProcessStripe.prototype.validationErrorHandler = function (event) {
        var $el = this.$checkoutForm.find(this.options.errorSelector)
        if (event.error) {
            $el.html(event.error.message);
        } else {
            $el.empty();
        }
    }

    ProcessStripe.prototype.submitFormHandler = function (event) {
        var self = this,
            $form = this.$checkoutForm,
            $paymentInput = $form.find('input[name="payment"]:checked')

        if ($paymentInput.val() !== 'stripe') return

        // Prevent the form from submitting with the default action
        event.preventDefault()

        this.stripe.confirmCardPayment(this.options.clientSecret, {
            payment_method: {
                card: this.card,
            },
        }).then(function (result) {
            if (result.error) {
                // Inform the user if there was an error.
                self.validationErrorHandler(result)
            } else {
                // Switch back to default to submit form
                $form.unbind('submitCheckoutForm').submit()
            }
        });
    }

    ProcessStripe.DEFAULTS = {
        publishableKey: undefined,
        clientSecret: undefined,
        partnerId: 'pp_partner_JZyCCGR3cOwj9S',
        cardSelector: '#stripe-card-element',
        errorSelector: '#stripe-card-errors',
    }

    // PLUGIN DEFINITION
    // ============================

    var old = $.fn.processStripe

    $.fn.processStripe = function (option) {
        var $this = $(this).first()
        var options = $.extend(true, {}, ProcessStripe.DEFAULTS, $this.data(), typeof option == 'object' && option)

        return new ProcessStripe($this, options)
    }

    $.fn.processStripe.Constructor = ProcessStripe

    $.fn.processStripe.noConflict = function () {
        $.fn.booking = old
        return this
    }

    $(document).render(function () {
        $('#stripePaymentForm').processStripe()
    })
}(window.jQuery)
