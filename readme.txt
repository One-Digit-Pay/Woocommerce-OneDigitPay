=== WooCommerce OneDigit Pay ===

Contributors: onedigitpay
Tags: woocommerce, payment gateway, onedigit pay, nigeria, ngn
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments in your WooCommerce store via OneDigit Pay. Customers are redirected to the secure OneDigit Pay checkout page.

== Description ==

OneDigit Pay is a payment gateway that allows merchants to accept payments through a secure checkout session. This plugin integrates OneDigit Pay with WooCommerce.

**Features:**

* Redirect customers to the OneDigit Pay hosted checkout page
* Automatic order completion when payment is confirmed
* NGN (Nigerian Naira) support with amount in Naira (e.g. 459.95)
* Configurable Merchant Token and API base URL in WooCommerce settings

**Documentation:** [OneDigit Pay API Documentation](https://hackmd.io/@IKpJxlpRSOunAWHlOLahFg/By47hX5jlx)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WordPress admin.
2. Activate the plugin.
3. Go to WooCommerce → Settings → Payments.
4. Enable "OneDigit Pay" and configure:
   * **Merchant Token** – From your OneDigit Pay dashboard (Settings → Tokens).
   * **API base URL** – Leave default for production: `https://prod-api-business.onedigitpay.com/api/v1`.
5. Ensure your store currency is set to NGN (OneDigit Pay only supports NGN).

== Support ==

For technical support or questions about OneDigit Pay:

* Email: support@onedigitpay.com
* Documentation: https://docs.onedigitpay.com
* Status: https://status.onedigitpay.com

== Changelog ==

= 1.0.0 =
* Initial release.
* Create checkout session and redirect to OneDigit Pay.
* Return URL handler to verify payment status and complete order.
* Admin settings: Merchant Token, API base URL, title, description.
