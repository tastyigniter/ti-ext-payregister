@if ($record['is_refundable'])
    <a 
        class="btn btn-outline-default" 
        data-control="refundmodal" 
        data-paymentid="{{ $record['payment_log_id'] }}"
        data-alias="{{ $this->alias }}"
    >Refund</a>
@else
    -
@endif