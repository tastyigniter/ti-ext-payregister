<div class="mt-3 p-3 border rounded">
    @if($this->model->showConnect())
        <h5>@lang('igniter.payregister::default.stripe.text_configure_connect')</h5>
        <div>
            <a href="{{site_url('ti_payregister/stripe_connect/account_link')}}">
                @lang('igniter.payregister::default.stripe.text_link_stripe_connect')
            </a>

        </div>
    @else
        <h5>@lang('igniter.payregister::default.stripe.text_stripe_connect_linked') <i class="fa fa-check"></i></h5>
    @endif
</div>
