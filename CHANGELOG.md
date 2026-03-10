## WooCommerce OneDigitPay – Changelog

### 0.3.4
- Fix fatal error on activation when `WC_OneDigitPay_Cron` was not loaded before the activation hook (load cron class inside activation/deactivation callbacks).

### 0.3.3
- Fix fatal error on plugin activation by ensuring the `WC_OneDigitPay_Cron` class is loaded before activation/deactivation hooks run.

### 0.3.0
- Introduced 'payment_mode' setting to choose between 'redirect' and 'inline' options for checkout.
- Implemented inline SDK loading on the checkout page when inline mode is active.
- Enhanced AJAX response handling to open OneDigitPay popup for inline payments without redirecting.

### 0.2.1
- **Store-hosted payment pending page** with a “Pay Now” button that opens OneDigitPay checkout in a new tab.
- **AJAX polling endpoint** (`odp_check_status`) to automatically detect when payment is completed or failed, including for guest checkouts.
- **Background WP-Cron job** to periodically check on-hold OneDigitPay orders and update their status if they complete or fail in the background.
- **API edge-case handling** when OneDigitPay returns HTTP 400 with “Session status is completed” for already-completed sessions.

### 0.2.0
- Internal release iteration towards the 0.2.x line (not widely published).

### 0.1.0
- General updates and refinements to the initial gateway implementation.

### 0.0.1
- Initial release.
- Create checkout session and redirect to OneDigitPay.
- Return URL handler to verify payment status and complete the order.
- Admin settings for Merchant Token, API base URL, title, and description.

