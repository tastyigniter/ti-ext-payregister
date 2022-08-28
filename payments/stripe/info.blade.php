<div class="mt-3 p-3 border rounded">
    <h5>Configure Webhook</h5>
    @if (is_null($formModel->getWebhook()))
    <div>
        Click the <b>Save</b> button to generate a webhook url.
    </div>
    @else
    <div>
        You can configure the webhook url <code>
            <?= site_url('ti_payregister/'.$formModel->getWebhook().'/handle') ?>
        </code>in your <a
            target="_blank"
            href="https://dashboard.stripe.com/webhooks"
        >Stripe Dashboard > Developers > Webhooks</a>
    </div>
    @endif
</div>