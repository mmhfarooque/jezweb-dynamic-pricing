# Changelog

All notable changes to Jezweb Dynamic Pricing & Discount for Woocommerce will be documented in this file.

## [1.5.0] - 2025-11-29

### Added
- **Loyalty Points System**: Complete points-based rewards program
  - Earn points on purchases (configurable points per dollar)
  - Redeem points for discounts at checkout
  - Points expiry with notifications
  - Bonus points campaigns
  - Points history and balance tracking
  - My Account integration
  - Admin points management

- **Referral Program**: Drive new customer acquisition
  - Unique referral links per customer
  - Referee discount (% or fixed) for new customers
  - Referrer rewards (coupons, points, or fixed discounts)
  - Cookie-based tracking (configurable duration)
  - Referral stats dashboard in My Account
  - Email notifications for rewards
  - `[jdpd_referral_link]` and `[jdpd_referral_stats]` shortcodes

- **Flash Sales Manager**: Create urgency with time-limited sales
  - Schedule flash sales with precise start/end times
  - Apply to specific products or categories
  - Stock-limited deals with live tracking
  - Countdown timers on product pages
  - Flash sale badges
  - Automatic status management (scheduled/active/expired)
  - `[jdpd_flash_sale_products]` and `[jdpd_flash_sale_countdown]` shortcodes

- **Review/Rating Discounts**: Incentivize product reviews
  - Reward customers for leaving reviews
  - Coupon, points, or immediate discount rewards
  - Verified purchase requirement option
  - Minimum rating and review length requirements
  - Rating-based product discounts (higher rated = cheaper)
  - Review reminder emails
  - Pending reviews dashboard in My Account

- **Exit Intent Offers**: Recover abandoning visitors
  - Mouse-leave detection for desktop
  - Scroll/time triggers for mobile
  - Customizable popup design
  - Targeted offers by page, product, or category
  - Customer targeting (guests, new, returning)
  - Auto-apply coupon option
  - Impression and conversion tracking
  - Frequency capping (session/day/week)

### Changed
- Updated database version to 1.3.0
- Added new database tables for referrals, flash sales, and exit offers

## [1.4.0] - 2025-11-28

### Added
- **Profit Margin Protection**: Set minimum profit margins and cost prices to prevent over-discounting
  - Cost price field on products and variations
  - Minimum margin enforcement across all discount rules
  - Profit margin column in products list
  - Low margin alerts and reports

- **Bundle Builder**: Let customers create custom product bundles
  - "Pick X for $Y" deals
  - Mix-and-match bundles
  - Percentage off bundles
  - Cheapest item free bundles
  - Frontend bundle builder interface
  - `[jdpd_bundle_builder]` shortcode

- **Geo-Location Pricing**: Set prices based on customer location
  - IP geolocation with ip-api.com integration
  - Pricing zones by country, state, city, or postcode
  - Postcode range support (e.g., "2000-2999")
  - Customer location selector widget

- **Urgency & Scarcity Features**: Create urgency to drive sales
  - Stock-based dynamic pricing
  - Live "X people viewing this" counter
  - Recent purchases counter
  - Limited quantity deal tracking

- **Wholesale/B2B Pricing Module**: Complete B2B pricing solution
  - Wholesale customer roles (Bronze, Silver, Gold, Platinum)
  - Product-specific wholesale prices
  - Minimum order amounts and quantities per tier
  - Tax exemption handling
  - Quote request system with `[jdpd_quote_form]` shortcode

- **Coupon Stacking Rules**: Control coupon interactions
  - Define coupon groups
  - Set stacking rules (stackable, exclusive, exclusive within group)
  - Control interaction with dynamic pricing
  - Maximum discount limits (absolute and percentage)
  - Coupon usage analytics

- **Birthday/Anniversary Discounts**: Reward loyal customers
  - Birthday field collection on registration/checkout
  - Automatic birthday discounts with configurable window
  - Order anniversary rewards
  - Milestone rewards (5th order, 10th, 25th, etc.)
  - Automated email notifications

- **Wishlist-Based Pricing**: Convert wishlist to sales
  - Built-in wishlist functionality
  - YITH WooCommerce Wishlist integration
  - TI WooCommerce Wishlist integration
  - Time-based discounts (longer on wishlist = bigger discount)
  - Price drop notifications
  - Wishlist reminder emails

- **Social Share Discounts**: Reward social sharing
  - Share buttons for Facebook, Twitter/X, Pinterest, LinkedIn, WhatsApp
  - Discount for sharing products
  - Referral tracking from shared links
  - Share analytics

- **Price History Graph**: Track and display price changes
  - Automatic price tracking on product updates
  - Interactive Chart.js price history graph
  - Historical high/low price display
  - EU Omnibus Directive compliance (30-day lowest price)
  - Configurable data retention

### Changed
- Updated database version to 1.2.0
- Added new database table for price history

## [1.3.0] - 2025-11-27

### Added
- Analytics dashboard with real-time statistics and conversion tracking
- Import/Export functionality for rules (JSON/CSV)
- REST API for external integrations
- Customer segments with automatic and manual assignment
- Advanced scheduling with recurring rules and time-of-day targeting
- A/B testing for pricing strategies with statistical analysis
- Email notifications for rule events
- Performance optimization with object caching
- Multi-language support with WPML/Polylang compatibility
- Multi-currency support
- Frontend enhancements with upsell messages and countdown timers

## [1.2.0] - 2025-11-26

### Changed
- Rebranded to Jezweb Dynamic Pricing
- Added GitHub auto-updater for automatic updates

### Security
- Enhanced input sanitization
- Improved nonce verification
- Added capability checks

## [1.1.0] - 2025-11-25

### Added
- Checkout countdown deals
- Improved quantity table display
- Order meta tracking for applied discounts

### Changed
- Performance optimizations

## [1.0.0] - 2025-11-24

### Added
- Initial release
- Price rules with quantity-based bulk pricing
- Cart rules with conditions
- Special offers (BOGO, Buy X Get Y, multi-buy deals)
- Gift products with automatic cart addition
- Conditions system (user roles, order history, cart contents)
- Scheduling system with start/end dates
- Exclusion lists for products and categories
- Quantity price tables on product pages
- HPOS compatible
