=== Jezweb Dynamic Pricing & Discount for Woocommerce ===
Contributors: jezweb, mmhfarooque
Tags: woocommerce, pricing, discounts, dynamic pricing, bulk pricing, quantity discounts
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.5.8
WC requires at least: 8.0
WC tested up to: 9.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Powerful dynamic pricing and discount rules for WooCommerce. Create quantity discounts, cart rules, BOGO offers, gift products, and special promotions.

== Description ==

**Jezweb Dynamic Pricing & Discounts** is a comprehensive pricing solution for WooCommerce that helps you create flexible pricing rules and discounts to boost sales.

= Key Features =

**Pricing Rules**
* Quantity-based bulk pricing with tiered discounts
* Category-wide discounts
* User role-based pricing
* Product-specific pricing rules
* Scheduled pricing with start/end dates

**Cart Rules**
* Cart total discounts
* Free shipping thresholds
* Buy X Get Y deals
* BOGO (Buy One Get One) offers
* Minimum/maximum quantity rules

**Special Offers**
* Multi-buy deals (e.g., 3 for $10)
* Gift products with automatic cart addition
* Checkout countdown deals
* Exclusive member discounts

**v1.4.0 New Features**

* **Profit Margin Protection** - Ensure discounts never go below cost price
* **Bundle Builder** - Let customers create custom product bundles with discounts
* **Geo-Location Pricing** - Set prices based on customer location
* **Urgency & Scarcity** - Stock-based pricing and live viewer counts
* **Wholesale/B2B Pricing** - Tiered pricing for business customers
* **Coupon Stacking Rules** - Control how multiple coupons interact
* **Birthday Discounts** - Automatic birthday rewards for customers
* **Wishlist-Based Pricing** - Discounts for items on wishlist over time
* **Social Share Discounts** - Reward customers for sharing on social media
* **Price History Graph** - Display price changes over time (EU Omnibus compliant)

**Advanced Features**
* Analytics dashboard with conversion tracking
* A/B testing for pricing strategies
* Customer segmentation
* REST API for external integrations
* Import/Export functionality
* Multi-currency support
* Email notifications

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/jezweb-dynamic-pricing`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin via WooCommerce > Dynamic Pricing

== Frequently Asked Questions ==

= Does this work with variable products? =

Yes, all pricing rules work with simple, variable, and variation products.

= Can I schedule discounts? =

Yes, all rules support scheduling with start and end dates, including advanced scheduling options.

= Is this compatible with HPOS? =

Yes, the plugin is fully compatible with WooCommerce High-Performance Order Storage.

== Changelog ==

= 1.5.8 =
* Debug release: Switched to PHP error_log for more reliable logging
* Added version identifier in logs to confirm code deployment
* Enhanced database update result tracking
* Investigating event sale save issue

= 1.5.7 =
* Debug release: Added extensive logging to track event sale save issues
* Improved column detection using SHOW COLUMNS instead of DESCRIBE
* Better error reporting for database operations

= 1.5.6 =
* Fixed event sale settings not persisting after save - improved database migration
* Added robust column checking to ensure event columns always exist
* Migration now runs on every admin load to handle edge cases

= 1.5.5 =
* Fixed event sale settings not saving when updating rules
* Fixed date format to use Australian format (dd-mm-yy)
* Restored blue background on admin page headers
* Fixed input field widths to match dropdowns
* Added vertical spacing between event sale form fields
* Database migration to add event sale columns for existing installations

= 1.5.4 =
* NEW: 14 Special Event Sale options for Australian retail calendar
* NEW: Custom Event option - create your own special event names
* NEW: Event discount badges displayed next to product prices (cart, checkout, product pages)
* NEW: Special Discount Style settings - customize badge background and text colors
* NEW: Live preview for badge styling in admin settings
* Improved event list: New Year's Day Sale, Back to School, Afterpay Day, Easter Sales, Click Frenzy Mayhem, VOSN, EOFY, Amazon Prime Day, Click Frenzy Main, Singles' Day, Black Friday, Cyber Monday, Green Monday, Boxing Day

= 1.5.3 =
* Added Special Event Sale option for retail calendar events
* Event info display with month and best categories suggestions
* UI improvements for special offers settings

= 1.5.2 =
* Removed blue header background from admin pages
* Settings page max-width 800px for better readability
* Minor UI improvements

= 1.5.1 =
* Combined settings page with modern sections layout
* Added Yes/No toggle switches for settings
* Fixed narrow dropdown inputs in rule edit form
* Merged plugin info and credits into single footer section

= 1.5.0 =
* Fixed singleton pattern issues with frontend classes
* Fixed class loading errors on plugin activation
* Improved textdomain loading for WordPress 6.7+
* Code stability improvements

= 1.4.0 =
* NEW: Profit Margin Protection - Prevent discounts from going below cost price
* NEW: Bundle Builder - Customer-created bundles with "Pick X for $Y" deals
* NEW: Geo-Location Pricing - IP-based pricing by country/region/city
* NEW: Urgency & Scarcity - Stock-based pricing and live viewer counts
* NEW: Wholesale/B2B Pricing - Tiered pricing for wholesale customers
* NEW: Coupon Stacking Rules - Control how multiple coupons interact
* NEW: Birthday/Anniversary Discounts - Automatic rewards on special dates
* NEW: Wishlist-Based Pricing - Time-based discounts for wishlisted items
* NEW: Social Share Discounts - Rewards for sharing products
* NEW: Price History Graph - Track and display price changes (EU Omnibus compliant)

= 1.3.0 =
* NEW: Analytics dashboard with real-time statistics
* NEW: Import/Export functionality for rules
* NEW: REST API for external integrations
* NEW: Customer segments with automatic assignment
* NEW: Advanced scheduling with recurring rules
* NEW: A/B testing for pricing strategies
* NEW: Email notifications for rule events
* NEW: Performance optimization with caching
* NEW: Multi-language and multi-currency support
* NEW: Frontend enhancements with upsell messages

= 1.2.0 =
* Rebranded to Jezweb Dynamic Pricing
* Added GitHub auto-updater
* Security improvements
* Bug fixes and stability improvements

= 1.1.0 =
* Added checkout countdown deals
* Improved quantity table display
* Added order meta tracking
* Performance optimizations

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.5.6 =
Critical fix: Ensures event sale database columns are created properly and settings persist after save.

= 1.5.5 =
Bug fix release: Fixes event sale settings not saving, restores admin UI styling, and adds database migration for event sale columns.

= 1.5.4 =
Major update: 14 special retail events with custom event support, discount badges on product prices, and customizable badge styling.
