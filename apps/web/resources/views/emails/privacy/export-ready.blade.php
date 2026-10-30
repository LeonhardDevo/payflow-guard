<x-mail::message>
# {{ __('emails.privacy.export_ready.heading') }}

@if (filled($workspaceName))
{{ __('emails.privacy.export_ready.intro_workspace', ['workspace' => $workspaceName]) }}
@else
{{ __('emails.privacy.export_ready.intro', ['app' => config('app.name')]) }}
@endif

<x-mail::button :url="$url">
{{ __('emails.privacy.export_ready.action') }}
</x-mail::button>

{{ __('emails.privacy.export_ready.expires', ['date' => $expiresAt]) }}

{{ __('emails.privacy.export_ready.outro') }}

{{ __('emails.common.regards') }}<br>
{{ __('emails.common.team', ['app' => config('app.name')]) }}

<x-slot:subcopy>
{{ __('emails.common.trouble_clicking', ['action' => __('emails.privacy.export_ready.action')]) }} <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
