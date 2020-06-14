<?php namespace Igniter\PayRegister\Controllers;
	
use Admin\Models\Payments_model;
use AdminMenu;
use Request;

/**
 * Refund Admin Controller
 */
class Refund extends \Admin\Classes\AdminController
{
    public $implement = [
        'Admin\Actions\FormController',
    ];
    
    public $formConfig = [
        'name' => 'lang:thoughtco.printer::default.text_form_name',
        'model' => 'Admin\Models\Orders_model',
        'edit' => [
            'title' => 'lang:admin::lang.form.edit_title',
            'redirect' => 'orders/edit/{id}',
            'redirectClose' => 'orders',
        ],
        'configFile' => 'orders',
    ];

    protected $requiredPermissions = 'Igniter.Payregister.Refunds';

    public function __construct()
    {
        parent::__construct();
        AdminMenu::setContext('sales', 'orders');        
    }
    
    public function formExtendQuery($query)
    {
        if ($locationId = $this->getLocationId()){
            $query->where('location_id', $locationId);
        }
    }
    
    public function formBeforeSave($model){
	    
	    // get amount to refund
	    $amount = Request::input('Order.order_total', 0);
	    $amount = floatval($amount);
	    
	    // valid amount
	    if ($amount > 0 && $amount <= $model->order_total) {
		  
            // set up payment gateway and process refund
            $paymentProvider = Payments_model::where('code', $model->payment)->first();
            if ($paymentProvider) {
	            	      
	            $klass = '\\'.$paymentProvider->class_name; 
	            $gateway = new $klass($paymentProvider);
	            if (method_exists($gateway, 'processRefund')) {
	                $response = $gateway->processRefund($amount, $model);
	                if ($response !== true) { // testing
		                
						\Flash::success('Refund successful')->now();
						
						$totals = $model->getOrderTotals()->toArray();
						
						$discountFound = false;
						$newTotal = $model->order_total;
						foreach ($totals as $i=>$t) {
							$t = (array)$t;
							if ($t['code'] == 'refund') {
								$t['value'] += $amount;
								$discountFound = true;
							} else if ($t['code'] == 'total') {
								$t['value'] -= $amount;
								$newTotal = $t['value'];
							}
							$totals[$i] = $t;
						}
						
						if (!$discountFound) {
							$totals[] = [
								'code' => 'refund',
								'title' => 'Refund',
								'value' => $amount,
								'priority' => 126	
							];
						}
												
						$model->addOrderTotals($totals);
						$model->order_total = $newTotal;
						$model->save();
						
	                } else {
						\Flash::error($response)->now();
	                }
	                
					echo Request::ajax() ? json_encode(['#notification' => $this->makePartial('flash')]) : false;
	                exit();
	                
	            } else {
					\Flash::warning('Error: cannot process refunds when payment was by '.$paymentProvider->name)->now();
					echo Request::ajax() ? json_encode(['#notification' => $this->makePartial('flash')]) : false;
					exit();
	            }
	            
            }
		  
		//  
		} else {
	        \Flash::warning('Error: the refund amount is invalid')->now();
            echo Request::ajax() ? json_encode(['#notification' => $this->makePartial('flash')]) : false;
            exit();
	    }
	    
    }

}
