/*
 * Small Change: the whole front-end script.
 *  - hides the Gift Aid address fields until the box is ticked
 *  - mounts Stripe's payment form on the payment page
 * Card details go straight from the browser to Stripe; they never touch this site's server.
 */
(function () {
    'use strict';

    function initGiftAid() {
        var toggle = document.querySelector('[data-gift-aid-toggle]');
        var fields = document.querySelector('[data-gift-aid-fields]');

        if (!toggle || !fields) {
            return;
        }

        function sync() {
            fields.hidden = !toggle.checked;
        }

        toggle.addEventListener('change', sync);
        sync();
    }

    function showMessage(element, text) {
        element.textContent = text;
        element.hidden = !text;
    }

    function initPayment() {
        var form = document.getElementById('payment-form');

        if (!form) {
            return;
        }

        var button = document.getElementById('pay-button');
        var message = document.getElementById('payment-message');

        // Stripe.js comes from Stripe's own address, so an ad blocker or a bad connection can stop it loading.
        if (typeof Stripe !== 'function' || !form.dataset.publishableKey) {
            showMessage(message, 'The payment form could not be loaded. Please refresh the page and try again.');
            return;
        }

        var actions = null;

        try {
            var checkout = Stripe(form.dataset.publishableKey).initCheckoutElementsSdk({
                clientSecret: form.dataset.clientSecret
            });

            checkout.createPaymentElement().mount('#payment-element');

            checkout.loadActions().then(function (result) {
                if (result.type === 'success') {
                    actions = result.actions;
                    button.disabled = false;
                } else {
                    showMessage(message, 'The payment form could not be loaded. Please refresh the page.');
                }
            }).catch(function () {
                showMessage(message, 'The payment form could not be loaded. Please refresh the page.');
            });
        } catch (error) {
            showMessage(message, 'The payment form could not be loaded. Please refresh the page.');
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!actions) {
                return;
            }

            button.disabled = true;
            showMessage(message, '');

            // On success the browser is redirected to the return URL, so this only runs on an error.
            actions.confirm().then(function (result) {
                if (result && result.type === 'error') {
                    showMessage(message, result.error.message);
                }

                button.disabled = false;
            }).catch(function () {
                showMessage(message, "We couldn't confirm your payment just now. Please check your email for a receipt before you try again.");
                button.disabled = false;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initGiftAid();
        initPayment();
    });
})();
