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
        this.stripe = Stripe(this.options.publishableKey, this.options.stripeOptions)

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

        this.$checkoutForm.on('submitCheckoutForm', $.proxy(this.submitFormHandler, this))

        var self = this
        this.$checkoutForm.on('submit', function () {
            if (self.$checkoutForm.find('input[name="payment"]:checked').val() !== 'stripe')
                return

            self.card.update({disabled: true});
        })

        this.$checkoutForm.on('ajaxFail', function () {
            self.card.update({disabled: false});
        })
    }

    ProcessStripe.prototype.validationErrorHandler = function (event) {
        var $el = this.$checkoutForm.find(this.options.errorSelector)
        if (event.error) {
            $el.html(event.error.message);
        } else {
            $el.empty();
        }

        $('.checkout-btn').prop('disabled', false)
        this.card.update({disabled: false});
    }

    ProcessStripe.prototype.submitFormHandler = function (event) {
        var self = this,
            $form = this.$checkoutForm,
            $paymentInput = $form.find('input[name="payment"]:checked')

        if ($paymentInput.val() !== 'stripe') return

        // Prevent the form from submitting with the default action
        event.preventDefault()

        this.stripe.confirmCardPayment(this.options.paymentIntentSecret, {
            payment_method: {
                card: this.card,
                billing_details: {
                    name: $form.find('input[name="first_name"]').val()+' '+$form.find('input[name="last_name"]').val()
                }
            },
            receipt_email: $form.find('input[name="email"]').val(),
        }).then(function (result) {
            var paymentIntentStatus = (result.error && result.error.payment_intent)
                ? result.error.payment_intent.status : null

            if (result.error && !(paymentIntentStatus === 'requires_capture' || paymentIntentStatus === 'succeeded')) {
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
        paymentIntentSecret: undefined,
        stripeOptions: undefined,
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
        $.fn.processStripe = old
        return this
    }

    $(document).render(function () {
        $('#stripePaymentForm').processStripe()
    })
}(window.jQuery)
