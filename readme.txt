=== WooCommerce OneDigitPay ===

Contributors: onedigitpay
Tags: woocommerce, payment gateway, onedigitpay, nigeria, ngn
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments in your WooCommerce store via OneDigitPay. Customers are redirected to the secure OneDigitPay checkout page.

== Description ==

OneDigitPay is a payment gateway that allows merchants to accept payments through a secure checkout session. This plugin integrates OneDigitPay with WooCommerce.

**Features:**

* Redirect customers to the OneDigitPay hosted checkout page
* Automatic order completion when payment is confirmed
* NGN (Nigerian Naira) support with amount in Naira (e.g. 459.95)
* Configurable Merchant Token and API base URL in WooCommerce settings

**Documentation:** [OneDigitPay API Documentation](https://hackmd.io/@IKpJxlpRSOunAWHlOLahFg/By47hX5jlx)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WordPress admin.
2. Activate the plugin.
3. Go to WooCommerce → Settings → Payments.
4. Enable "OneDigitPay" and configure:
   * **Merchant Token** – From your OneDigitPay dashboard (Settings → Tokens).
   * **API base URL** – Leave default for production: `https://prod-api-business.onedigitpay.com/api/v1`.
5. Ensure your store currency is set to NGN (OneDigitPay only supports NGN).

== Support ==

For technical support or questions about OneDigitPay:

* Email: support@onedigitpay.com
* Documentation: https://docs.onedigitpay.com
* Status: https://status.onedigitpay.com

== Changelog ==

= 0.2.1 =
* Add store-hosted “payment pending” page with Pay Now button that opens OneDigitPay checkout in a new tab.
* Add AJAX polling endpoint to automatically detect when payment is completed or failed (supports guests).
* Add background WP-Cron job to periodically check on-hold OneDigitPay orders and update their status.
* Handle OneDigitPay edge case where an already-completed session returns HTTP 400 with “Session status is completed”.

= 0.1.0 =
* Update.

= 0.0.2 =
* Update.

= 0.0.1 =
* Initial release.
* Create checkout session and redirect to OneDigitPay.
* Return URL handler to verify payment status and complete order.
* Admin settings: Merchant Token, API base URL, title, description.
