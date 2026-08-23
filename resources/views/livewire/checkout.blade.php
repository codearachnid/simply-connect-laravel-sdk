{{--
    Default view for <livewire:simply-connect.checkout />.
    Publish with `php artisan vendor:publish --tag=simply-connect-views` to restyle,
    or extend the component and point render() at your own view.
--}}
<div class="simply-connect">
    <x-simply-connect::scripts />

    @if ($status === null)
        <div wire:ignore
             wire:key="simply-connect-{{ $sessionToken }}"
             x-data
             x-init="window.SimplyConnect.mount($el, @js($this->checkoutConfig()), { onResult: (result) => $wire.handleResult(result) })"
             class="simply-connect__form"></div>
    @else
        <div class="simply-connect__result simply-connect__result--{{ $status }}" role="status">
            @switch($status)
                @case('approved')
                    <p>{{ __('Thank you — your payment was approved.') }}</p>
                    @break
                @case('pending')
                    <p>{{ __('Your payment is being processed. We will confirm it shortly.') }}</p>
                    @break
                @case('declined')
                    <p>{{ __('Your payment was declined.') }} @if ($error)<span class="simply-connect__reason">{{ $error }}</span>@endif</p>
                    @break
                @case('cancelled')
                    <p>{{ __('Payment cancelled.') }}</p>
                    @break
                @default
                    <p>{{ $error ?? __('Something went wrong.') }}</p>
            @endswitch

            @if (in_array($status, ['declined', 'cancelled', 'error'], true))
                <button type="button" wire:click="retry" class="simply-connect__retry">{{ __('Try again') }}</button>
            @endif
        </div>
    @endif
</div>
