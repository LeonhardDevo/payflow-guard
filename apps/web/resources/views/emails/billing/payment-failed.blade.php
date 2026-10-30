<x-mail::message>
# {{ __('emails.billing.payment_failed.heading') }}

{{ __('emails.billing.payment_failed.intro', ['workspace' => $workspaceName, 'plan' => $planName]) }}

@if (filled($amountText))
{{ __('emails.billing.payment_failed.amount', ['amount' => $amountText]) }}
@endif

@if (filled($nextAttempt))
{{ __('emails.billing.payment_failed.next_attempt', ['date' => $nextAttempt]) }}
@else
{{ __('emails.billing.payment_failed.no_retry') }}
@endif

<x-mail::button :url="$url">
{{ __('emails.billing.payment_failed.action') }}
</x-mail::button>

@if (filled($invoiceUrl))
{{ __('emails.billing.payment_failed.invoice') }} [{{ __('emails.billing.payment_failed.invoice_link') }}]({{ $invoiceUrl }})
@endif

{{ __('emails.billing.payment_failed.outro') }}

{{ __('emails.common.regards') }}<br>
{{ __('emails.common.team', ['app' => config('app.name')]) }}

<x-slot:subcopy>
{{ __('emails.common.trouble_clicking', ['action' => __('emails.billing.payment_failed.action')]) }} <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
