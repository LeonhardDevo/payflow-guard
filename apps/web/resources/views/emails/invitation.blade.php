<x-mail::message>
# {{ __('emails.invitation.heading', ['workspace' => $workspaceName]) }}

{{ __('emails.invitation.intro', ['inviter' => $inviterName, 'workspace' => $workspaceName, 'app' => config('app.name')]) }}

<x-mail::panel>
**{{ __('emails.invitation.role_label') }}:** {{ $roleLabel }}<br>
**{{ __('emails.invitation.expires_label') }}:** {{ $expiresAt }}
</x-mail::panel>

<x-mail::button :url="$url">
{{ __('emails.invitation.action') }}
</x-mail::button>

{{ __('emails.invitation.outro') }}

{{ __('emails.common.regards') }}<br>
{{ __('emails.common.team', ['app' => config('app.name')]) }}

<x-slot:subcopy>
{{ __('emails.common.trouble_clicking', ['action' => __('emails.invitation.action')]) }} <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
