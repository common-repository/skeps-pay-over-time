/**
 * Backward compatible.
 *
 * @package WooCommerce
 */

jQuery(document).ready(
    function ($) {


        /**
         * Update the amount.
         *
         * This will update the `data-opportunity-amount` attribute in Monthly Payment Messaging
         * element and clear the existing rendered message.
         *
         * @param {number} amount - New amount in cents.
         */
        function updateAmount(amount) {
            if (!isSkepsBNPLExists()) {
                return;
            }

            var promoBanners = document.querySelectorAll('[name="skeps-promotion-banner"][data-promotion-type="product"]');
            if(!promoBanners) {
                return;
            }
            for(let i = 0; i<promoBanners.length; i++) {
                const banner = promoBanners[i];
                banner.setAttribute('data-opportunity-amount', amount);
                banner.innerHTML = '';
            }
        }
        /**
         * Init support for composite product.
         */
        function initCompositeProductSupport() {
            var composite_data = $('.composite_data');
            if (composite_data.length) {
                composite_data.on('wc-composite-initializing', compositeUpdateSkepsBNPLMonthlyPaymentMessaging);
            }
        }

        /**
         * Update amount when component selection is changed in composite product.
         *
         * @param {object} event - Event.
         * @param {object} composite - Composite object.
         */
        function compositeUpdateSkepsBNPLMonthlyPaymentMessaging(event, composite) {
            $(document.body).off('found_variation', '.variations_form', onVariationUpdated);

            let updateAmount = onComponentUpdated.bind({ composite: composite });

            composite.actions.add_action('component_selection_changed', updateAmount, 99);
            composite.actions.add_action('component_quantity_changed', updateAmount, 99);
        }

        /**
         * Update amount when composite component is updated.
         *
         * @callback onComponentUpdated
         */
        function onComponentUpdated() {
            var totals = this.composite.api.get_composite_totals();

            if ('object' !== typeof totals) {
                return;
            }
            if (!totals.price) {
                return;
            }

            updateAmount(totals.price);
        }

        /**
         * Update amount based on variation price.
         *
         * @param {object} event - Event.
         * @param {object} variation - Variation properties.
         *
         * @callback onVariationUpdated
         */
        function onVariationUpdated(event, variation) {
            console.log(event, variation);
            updateAmount(variation.display_price)
        }


        /**
         * Check if SkepsBNPL and its dependencies exist.
         *
         * @return {bool} Returns true if SkepsBNPL and its dependencies exist.
         */
        function isSkepsBNPLExists() {
            return (
                'undefined' !== typeof SKEPS_FINANCING || 'undefined' !== typeof ELAVON_SKEPS_FINANCING
            );
        }

        /**
         * Init.
         */
        function init() {
            if (isSkepsBNPLExists()) {
                // For a roduct, monitor for the customer changing the variation.
                $(document.body).on('found_variation', '.variations_form', onVariationUpdated);

                // Support updated price in composite product.
                initCompositeProductSupport();
            }
        }

        init();
    }
);