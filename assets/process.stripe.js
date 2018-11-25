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

        this.init()
    }

    ProcessStripe.prototype.init = function () {
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
        if (event.error) {
            $(this.options.errorSelector).html(event.error.message);
        } else {
            $(this.options.errorSelector).empty();
        }
    }

    ProcessStripe.prototype.submitFormHandler = function (event) {
        var $form = this.$checkoutForm,
            $paymentInput = $form.find('input[name="payment"]:checked')

        if ($paymentInput.val() !== 'stripe') return

        // Prevent the form from submitting with the default action
        event.preventDefault()

        this.stripe.createToken(this.card).then(function (result) {
            if (result.error) {
                // Inform the user if there was an error.
                $form.find(this.options.errorSelector).html(result.error.message);
            } else {
                // Insert the token into the form so it gets submitted to the server
                $form.find('input[name="stripe_token"]').val(result.token.id);

                // Switch back to default to submit form
                $form.unbind('submitCheckoutForm').submit()
            }
        });
    },

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