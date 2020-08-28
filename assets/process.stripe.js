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
        this.stripe = Stripe(this.options.publishableKey)

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
		paymentRequest.canMakePayment().then(function(result) {
			if (result) {
				paymentRequestButton.mount(this.options.paymentRequestSelector);
			} else {
				document.querySelector(this.options.paymentRequestSelector).style.display = 'none';
			}
		}.bind(this));
		
		var self = this;
		paymentRequest.on('paymentmethod', function(ev) {
			
			// Confirm the PaymentIntent without handling potential next actions (yet).
			self.stripe.confirmCardPayment(
				self.options.paymentRequestIntent,
				{payment_method: ev.paymentMethod.id},
				{handleActions: false}
			).then(function(confirmResult) {
				if (confirmResult.error) {
					ev.complete('fail');
				} else {
					ev.complete('success');
					// Let Stripe.js handle the rest of the payment flow.
					self.stripe.confirmCardPayment(self.options.paymentRequestIntent)
					.then(function(result) {
						console.log(result);
						if (result.error) {
							alert(confirmResult.error);
							location.reload();
						} else {
							console.log(self.$checkoutForm);
							// set a fixed value that we check server side
							self.$checkoutForm.find('input[name="payment_button"]').val(1);
                			self.$checkoutForm.unbind('submitCheckoutForm').submit()
						}
					});
				}
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