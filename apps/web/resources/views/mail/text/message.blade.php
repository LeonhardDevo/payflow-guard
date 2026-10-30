<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer: sender identity + privacy link on every message (PLATFORM.md §10.2) --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ config('app.name') }}. {{ __('emails.common.rights') }}
            {{ __('emails.common.sender', ['company' => config('legal.company.name'), 'address' => config('legal.company.address')]) }}
            {{ __('emails.common.privacy') }}: {{ route('legal', ['doc' => 'privacy']) }}
            {{ __('emails.common.automated') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
