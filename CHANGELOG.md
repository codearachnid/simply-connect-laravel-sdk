# Changelog

## 1.0.0 — 2026-08-23

Initial release.

- `SimplyConnect` facade/manager over `codearachnid/simply-connect-php-sdk` with config defaults for orders and checkout options
- `<livewire:simply-connect.checkout />` (Livewire 3/4) with server-side verification, locked properties, browser events and extension hooks
- `<x-simply-connect::checkout />` Blade component and `resources/js/simply-connect.js` helper (Vue/React/Inertia) emitting DOM events
- Verified DMN webhook route firing `WebhookReceived`
- Requests via Laravel's HTTP client; `SimplyConnect::fake()`, `assertSent()`, `assertNotSent()`, `fakeWebhook()` test helpers
