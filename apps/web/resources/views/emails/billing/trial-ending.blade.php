<x-mail::message>
# {{ __('emails.billing.trial_ending.heading') }}

{{ __('emails.billing.trial_ending.intro', ['workspace' => $workspaceName, 'plan' => $planName, 'date' => $endsAt ?? '']) }}

@if ($cancelAtPeriodEnd)
{{ __('emails.billing.trial_ending.cancels') }}
@else
{{ __('emails.billing.trial_ending.renews') }}
@endif

<x-mail::button :url="$url">
{{ __('emails.billing.trial_ending.action') }}
</x-mail::button>

{{ __('emails.common.regards') }}<br>
{{ __('emails.common.team', ['app' => config('app.name')]) }}

<x-slot:subcopy>
{{ __('emails.common.trouble_clicking', ['action' => __('emails.billing.trial_ending.action')]) }} <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
