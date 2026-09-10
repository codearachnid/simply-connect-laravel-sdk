/**
 * Tiny browser helper for Nuvei Simply Connect. Loads checkout.js on demand,
 * mounts checkout({...}) into an element and re-emits callbacks as DOM events
 * (simply-connect:ready, simply-connect:result, simply-connect:payment-event, ...)
 * so Blade, Alpine, Livewire, Vue or React can listen without wiring Nuvei
 * callbacks by hand.
 *
 *   SimplyConnect.mount('#checkout', config, { onResult: r => ... })  // -> Promise
 *   el.addEventListener('simply-connect:result', e => e.detail)
 *
 * Works as a plain <script> (window.SimplyConnect) and as an ES/CommonJS import.
 */
(function (root, factory) {
    var api = factory(root);
    if (typeof module === 'object' && module.exports) module.exports = api;
    root.SimplyConnect = api;
})(typeof window !== 'undefined' ? window : this, function (root) {
    var SCRIPT_URL = 'https://cdn.safecharge.com/safecharge_resources/v1/checkout/checkout.js';
    var EMITTED = ['onReady', 'onResult', 'onPaymentEvent', 'onFormValidated', 'onSelectPaymentMethod', 'onPaymentFormChange', 'onSuggestedAmount', 'upoDeleted'];
    var loading = null;
    var counter = 0;

    function eventName(callback) {
        return 'simply-connect:' + callback.replace(/^on/, '').replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase();
    }

    var api = {
        /** Load checkout.js once. Resolves with the global checkout() function. */
        load: function () {
            if (typeof root.checkout === 'function') return Promise.resolve(root.checkout);
            if (loading) return loading;
            loading = new Promise(function (resolve, reject) {
                var script = document.querySelector('script[src="' + SCRIPT_URL + '"]');
                if (!script) {
                    script = document.createElement('script');
                    script.src = SCRIPT_URL;
                    script.async = true;
                    document.head.appendChild(script);
                }
                script.addEventListener('load', function () { resolve(root.checkout); });
                script.addEventListener('error', function () {
                    loading = null;
                    reject(new Error('Simply Connect: failed to load ' + SCRIPT_URL));
                });
            });
            return loading;
        },

        /**
         * Render the checkout into `target` (element or selector) using the server-built
         * config. `callbacks` are merged over config (e.g. { onResult, prePayment }).
         * Destroys any previous instance first. Resolves once checkout() has been called.
         */
        mount: function (target, config, callbacks) {
            var el = typeof target === 'string' ? document.querySelector(target) : target;
            if (!el) return Promise.reject(new Error('Simply Connect: mount target not found'));
            if (!el.id) el.id = 'simply-connect-' + (++counter);

            var options = Object.assign({}, config, callbacks || {}, { renderTo: '#' + el.id });
            EMITTED.forEach(function (name) {
                var user = options[name];
                options[name] = function () {
                    var args = Array.prototype.slice.call(arguments);
                    el.dispatchEvent(new CustomEvent(eventName(name), { detail: args.length > 1 ? args : args[0], bubbles: true }));
                    return typeof user === 'function' ? user.apply(this, args) : undefined;
                };
            });

            return api.load().then(function (checkout) {
                api.destroy();
                el.innerHTML = '';
                checkout(options);
                return checkout;
            });
        },

        /** Tear down the current checkout instance, if the library exposes destroy(). */
        destroy: function () {
            if (root.checkout && typeof root.checkout.destroy === 'function') {
                try { root.checkout.destroy(); } catch (e) { /* already gone */ }
            }
        }
    };

    return api;
});
