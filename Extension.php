<?php namespace Igniter\PayRegister;
	
use Event;
use Admin\Widgets\Form;
use System\Classes\BaseExtension;

class Extension extends BaseExtension
{
    public function boot()
    {
        $this->extendActionFormFields();
	}

    public function registerPaymentGateways()
    {
        return [
            'Igniter\PayRegister\Payments\Cod' => [
                'code' => 'cod',
                'name' => 'lang:igniter.payregister::default.cod.text_payment_title',
                'description' => 'lang:igniter.payregister::default.cod.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\PaypalExpress' => [
                'code' => 'paypalexpress',
                'name' => 'lang:igniter.payregister::default.paypal.text_payment_title',
                'description' => 'lang:igniter.payregister::default.paypal.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\AuthorizeNetAim' => [
                'code' => 'authorizenetaim',
                'name' => 'lang:igniter.payregister::default.authorize_net_aim.text_payment_title',
                'description' => 'lang:igniter.payregister::default.authorize_net_aim.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\Stripe' => [
                'code' => 'stripe',
                'name' => 'lang:igniter.payregister::default.stripe.text_payment_title',
                'description' => 'lang:igniter.payregister::default.stripe.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\Mollie' => [
                'code' => 'mollie',
                'name' => 'lang:igniter.payregister::default.mollie.text_payment_title',
                'description' => 'lang:igniter.payregister::default.mollie.text_payment_desc',
            ],
            'Igniter\PayRegister\Payments\Square' => [
                'code' => 'square',
                'name' => 'lang:igniter.payregister::default.square.text_payment_title',
                'description' => 'lang:igniter.payregister::default.square.text_payment_desc',
            ],
        ];
    }
    
    protected function extendActionFormFields()
    {
        Event::listen('admin.form.extendFieldsBefore', function (Form $form) {       
	        
	        // if its an orders form	        
            if ($form->model instanceof \Admin\Models\Orders_model) {
	            
	            if (isset($form->tabs['fields']['payment_method[name]'])) {
		            
		            // add a refund button beside 
		            $form->tabs['fields']['payment_method[name]']['type'] = 'addon';
					$form->tabs['fields']['payment_method[name]']['addonRight'] = [
				         'tag' => 'a',
				         'label' => 'Refund',
				         'attributes' => [
					         'href' => admin_url('igniter/payregister/refund/edit/'.$form->model->order_id),
					         'class' => 'btn btn-outline-default',
					         'id' => 'igniter_payregister_refund'
				         ]
					];
				
				}

            }
            
        }); 
    }
}
