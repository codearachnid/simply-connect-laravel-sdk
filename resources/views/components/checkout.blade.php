{{--
    <x-simply-connect::checkout :order="$order" :options="['country' => 'US']" />

    $order   OpenOrderResponse from SimplyConnect::openOrder(), or a raw sessionToken string
    $options extra checkout({...}) options (config('simply-connect.checkout') is applied underneath)

    Listen for results on the element (or anywhere above it, the events bubble):
        el.addEventListener('simply-connect:result', e => fetch('/pay/verify', {...e.detail}))
    Then ALWAYS verify with SimplyConnect::getPaymentStatus($sessionToken) on the server.
--}}
@props(['order', 'options' => [], 'id' => 'simply-connect-checkout'])
@php($config = \SimplyConnect\Laravel\Facades\SimplyConnect::checkout($order, $options)->jsonSerialize())
<x-simply-connect::scripts />
<div {{ $attributes->merge(['id' => $id]) }}></div>
<script>window.SimplyConnect.mount(@js('#' . $id), @js($config));</script>
