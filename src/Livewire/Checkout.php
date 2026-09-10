<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use SimplyConnect\Exception\SimplyConnectException;
use SimplyConnect\Laravel\Facades\SimplyConnect;
use SimplyConnect\Response\TransactionResponse;
use SimplyConnect\TransactionStatus;

/**
 * Drop-in checkout: opens the order on mount, renders Nuvei's form, and when
 * the customer pays verifies the outcome server-side via /getPaymentStatus.
 *
 *   <livewire:simply-connect.checkout amount="49.90" currency="USD" client-unique-id="order-1001"
 *       :user-token-id="auth()->id()" :order="['billingAddress' => [...]]" :options="['country' => 'US']" />
 *
 * Browser events (listen with #[On('simply-connect:approved')] in a parent
 * component or Alpine/JS): simply-connect:approved, :declined, :pending,
 * :cancelled, :error — payload ['status' => ..., 'transaction' => [...]].
 *
 * Extend this class to hook into outcomes (completed()), build the order from
 * your cart (orderParams()) or use your own view (render()).
 */
class Checkout extends Component
{
    #[Locked] public string $amount = '';
    #[Locked] public string $currency = 'USD';
    #[Locked] public ?string $clientUniqueId = null;
    #[Locked] public ?string $userTokenId = null;

    /** @var array<string, mixed> extra /openOrder params (billingAddress, userDetails, items, transactionType, ...) */
    #[Locked] public array $order = [];

    /** @var array<string, mixed> extra checkout({...}) options (country, locale, savePM, pmBlacklist, ...) */
    #[Locked] public array $options = [];

    #[Locked] public ?string $sessionToken = null;
    #[Locked] public ?string $orderId = null;

    /** approved | declined | pending | cancelled | error, or null while the form is showing */
    #[Locked] public ?string $status = null;
    #[Locked] public ?string $error = null;

    /** @var array<string, mixed>|null safe subset of the verified transaction */
    #[Locked] public ?array $transaction = null;

    public function mount(): void
    {
        $this->clientUniqueId ??= (string) Str::uuid();
        $this->openOrder();
    }

    /**
     * Called from the browser when Nuvei's onResult fires.
     *
     * @param array<string, mixed> $result
     */
    public function handleResult(array $result = []): void
    {
        if ($this->sessionToken === null || $this->status !== null) {
            return;
        }
        if (!empty($result['cancelled'])) {
            $this->finish('cancelled');

            return;
        }
        if (!empty($result['session_expired'])) {
            $this->finish('error', error: 'Your payment session expired. Please try again.');

            return;
        }

        try {
            $tx = SimplyConnect::getPaymentStatus($this->sessionToken);
        } catch (SimplyConnectException $e) {
            report($e);
            $this->finish('error', error: 'We could not verify your payment. Please try again.');

            return;
        }

        $this->transaction = [
            'transactionId' => $tx->transactionId(),
            'transactionType' => $tx->transactionType(),
            'transactionStatus' => $tx->status()?->value,
            'amount' => $tx->amount(),
            'currency' => $tx->currency(),
            'userPaymentOptionId' => $tx->userPaymentOptionId(),
            'card' => array_intersect_key($tx->card(), array_flip(['cardBrand', 'cardType', 'last4Digits', 'bin', 'ccExpMonth', 'ccExpYear'])),
            'clientUniqueId' => $this->clientUniqueId,
        ];

        $status = match (true) {
            $tx->isApproved() => 'approved',
            $tx->isDeclined() => 'declined',
            $tx->status() === TransactionStatus::Pending => 'pending',
            default => 'error',
        };
        $this->finish($status, error: match ($status) {
            'declined' => $tx->failureReason(),
            'error' => $tx->failureReason() ?? 'Payment failed.',
            default => null,
        });
        $this->completed($status, $tx);
    }

    /** Start over with a fresh session (after a decline, cancel or error). */
    public function retry(): void
    {
        $this->status = $this->error = $this->transaction = null;
        $this->openOrder();
    }

    /**
     * Options for the browser-side checkout({...}); override to customise.
     *
     * @return array<string, mixed>
     */
    public function checkoutConfig(): array
    {
        return SimplyConnect::checkout((string) $this->sessionToken, array_replace(array_filter([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'userTokenId' => $this->userTokenId,
            'clientUniqueId' => $this->clientUniqueId,
        ], fn ($v) => $v !== null && $v !== ''), $this->options))->jsonSerialize();
    }

    public function render(): View
    {
        return view()->make('simply-connect::livewire.checkout');
    }

    /*
    |--------------------------------------------------------------------------
    | Extension points
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> parameters for /openOrder */
    protected function orderParams(): array
    {
        return array_replace_recursive(array_filter([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'clientUniqueId' => $this->clientUniqueId,
            'userTokenId' => $this->userTokenId,
        ], fn ($v) => $v !== null && $v !== ''), $this->order);
    }

    /** Called after a verified outcome; $status is approved | declined | pending | error. */
    protected function completed(string $status, TransactionResponse $tx): void
    {
    }

    private function openOrder(): void
    {
        try {
            $order = SimplyConnect::openOrder($this->orderParams());
            $this->sessionToken = $order->sessionToken();
            $this->orderId = $order->orderId();
        } catch (SimplyConnectException $e) {
            report($e);
            $this->sessionToken = null;
            $this->finish('error', error: 'Payments are temporarily unavailable. Please try again later.');
        }
    }

    private function finish(string $status, ?string $error = null): void
    {
        $this->status = $status;
        $this->error = $error;
        $this->dispatch('simply-connect:' . $status, status: $status, transaction: $this->transaction, error: $error, clientUniqueId: $this->clientUniqueId);
    }
}
