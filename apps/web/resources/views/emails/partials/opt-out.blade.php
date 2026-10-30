<x-mail::subcopy>
{{ __('emails.privacy.opt_out.notice', ['app' => config('app.name')]) }} [{{ __('emails.privacy.opt_out.action') }}]({{ $optOutUrl }})
</x-mail::subcopy>
