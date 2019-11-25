+function ($) {
    "use strict"

    var ProcessStripe = function (element, options) {
        this.$el = $(element)
        this.options = options || {}
        this.$checkoutForm = this.$el.closest('#checkout-form')
        this.stripe = null
        this.card = null

        if (this.options.publishableKey === undefined)
            throw new Error('Missing stripe publishable key')

        $('[name=payment][value=stripe]', this.$checkoutForm).on('change', $.proxy(this.init, this))
    }

    ProcessStripe.prototype.init = function () {
        if (this.stripe !== null)
            return

        // Create a Stripe client.
        this.stripe = Stripe(this.options.publishableKey)

        // Create an instance of the card Element.
        this.card = this.stripe.elements().create('card')

        // Add an instance of the card Element into the `card-element` <div>.
        this.card.mount(this.options.cardSelector);

        // Handle real-time validation errors from the card Element.
        this.card.addEventListener('change', $.proxy(this.validationErrorHandler, this))

        this.$checkoutForm.on('submitCheckoutForm', $.proxy(this.submitFormHandler, this))
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

        this.stripe.createPaymentMethod('card', this.card).then(function (result) {
            if (result.error) {
                // Inform the user if there was an error.
                self.validationErrorHandler(result)
            } else {
                // Insert the token into the form so it gets submitted to the server
                $form.find('input[name="stripe_payment_method"]').val(result.paymentMethod.id);

                // Switch back to default to submit form
                $form.unbind('submitCheckoutForm').submit()
            }
        });
    }

    ProcessStripe.DEFAULTS = {
        publishableKey: undefined,
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