<x-mail::message>
# {{ filled($workspaceName) ? __('emails.privacy.workspace_deletion.heading', ['workspace' => $workspaceName]) : __('emails.privacy.account_deletion.heading') }}

{{ __('emails.privacy.greeting', ['name' => $name]) }}

@if (filled($workspaceName))
{{ __('emails.privacy.workspace_deletion.intro', ['workspace' => $workspaceName, 'date' => $scheduledFor]) }}
@else
{{ __('emails.privacy.account_deletion.intro', ['app' => config('app.name'), 'date' => $scheduledFor]) }}
@endif

<x-mail::button :url="$url">
{{ __('emails.privacy.cancel_action') }}
</x-mail::button>

{{ __('emails.privacy.not_you') }}

{{ __('emails.common.regards') }}<br>
{{ __('emails.common.team', ['app' => config('app.name')]) }}

<x-slot:subcopy>
{{ __('emails.common.trouble_clicking', ['action' => __('emails.privacy.cancel_action')]) }} <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
