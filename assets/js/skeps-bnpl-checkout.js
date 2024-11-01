/**
 * Checkout for Skeps Pay-Over-Time
 *
 * @package WooCommerce
 */
jQuery(document).ready(
    function($) {
        if (("undefined" !== typeof skepsBNPLData)) {
            $('body').block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        }
    }
)

window.addEventListener('load', () => {
    if (("undefined" !== typeof skepsBNPLData)) {
        var successForm = createSuccessForm();
        var config = {
            mode: 'modal',
            merchantId: skepsBNPLData.checkout.merchant_id,
            cartAmount: skepsBNPLData.checkout.order_amount,
            storeId: skepsBNPLData.checkout.store_id,
            customerDetails: {
                firstName: skepsBNPLData.customer.name.first,
                lastName: skepsBNPLData.customer.name.last,
                email: skepsBNPLData.customer.email
            },
            billingAddress:{
                streetAddress: skepsBNPLData.billing.address.line1 + skepsBNPLData.billing.address.line2,
                city: skepsBNPLData.billing.address.city,
                state: skepsBNPLData.billing.address.state,
                zipcode: skepsBNPLData.billing.address.zipcode
            }
        };
        var handlers = {
            onSuccess: (e) => {
                var input1 = document.createElement('input');
                input1.setAttribute('type', 'hidden');
                input1.setAttribute('name', 'orderId');
                input1.setAttribute('value', e.data.orderId);
                successForm.appendChild(input1);
                successForm.submit();
            },
            onFailure: () => {
                window.location = skepsBNPLData.merchant.user_cancel_url
            }
        }
        window.SKEPS_FINANCING.initProcess(config, handlers);
    }

    function createSuccessForm() {
        var form = document.createElement('form');
        form.setAttribute('action', skepsBNPLData.merchant.user_confirmation_url);
        form.setAttribute('method', 'POST');
        form.style.display = 'none';
        document.body.appendChild(form);
        return form;
    }
})