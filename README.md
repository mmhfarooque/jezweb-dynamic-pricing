# Jezweb Dynamic Pricing & Discounts for WooCommerce

A powerful dynamic pricing and discount rules plugin for WooCommerce that allows you to create flexible pricing strategies for your online store.

## Description

Jezweb Dynamic Pricing & Discounts helps you increase sales by offering customers attractive pricing options. Create quantity-based discounts, cart rules, BOGO offers, gift products, and more with an easy-to-use interface.

### Features

- **Price Rules** - Apply percentage or fixed discounts to products
- **Quantity Discounts** - Set different prices based on purchase quantity with visual price tables
- **Cart Rules** - Apply discounts based on cart total, item count, or other conditions
- **Special Offers** - Create BOGO (Buy One Get One), Buy X Get Y, and multi-buy deals
- **Gift Products** - Automatically add free or discounted products to cart
- **Conditions System** - Target specific user roles, customer history, cart contents, and more
- **Scheduling** - Set start and end dates for promotions
- **Exclusions** - Exclude specific products or categories from discounts
- **Display Options** - Show quantity tables, sale badges, and promotional notices
- **Checkout Deals** - Last-minute upsell offers with countdown timers
- **Order Integration** - Track applied discounts in orders and emails
- **HPOS Compatible** - Full compatibility with WooCommerce High-Performance Order Storage

## Requirements

- WordPress 6.0 or higher
- WooCommerce 8.0 or higher
- PHP 8.0 or higher

## Installation

1. Download the plugin from GitHub
2. Upload to `/wp-content/plugins/jezweb-dynamic-pricing`
3. Activate through WordPress Plugins menu
4. Go to **Dynamic Pricing** menu to create your first rule

## Usage

### Creating a Price Rule

1. Go to **Dynamic Pricing > Add New Rule**
2. Enter a name for your rule
3. Select "Price Rule" as the rule type
4. Choose a discount type (percentage, fixed, or fixed price)
5. Enter the discount value
6. Select which products it applies to
7. Add quantity ranges for bulk pricing (optional)
8. Set conditions, schedule, and exclusions (optional)
9. Save the rule

### Creating a Cart Rule

1. Go to **Dynamic Pricing > Add New Rule**
2. Select "Cart Rule" as the rule type
3. Set your discount
4. Add conditions (e.g., cart total >= $100)
5. Save the rule

### Creating Special Offers (BOGO)

1. Go to **Dynamic Pricing > Add New Rule**
2. Select "Special Offer" as the rule type
3. Choose offer type (BOGO, Buy X Get Y, etc.)
4. Configure quantities and discount
5. Save the rule

### Shortcodes

- `[jdpd_quantity_table product_id="123"]` - Display quantity pricing table
- `[jdpd_savings product_id="123"]` - Show savings amount
- `[jdpd_discount_message product_id="123"]` - Display promotional message

## Auto-Updates

This plugin automatically checks for updates from the GitHub repository. When a new version is released, you'll see the update notification in your WordPress admin.

## Author

**Mahmmud Farooque**
[Jezweb - Australian Web Development](https://jezweb.com.au)

## Support

For support and feature requests, please [open an issue](https://github.com/developer-jeremaiah/jezweb-dynamic-pricing/issues) on GitHub.

## License

GPL v2 or later

## Changelog

### 1.0.0
- Initial release
- Price rules with quantity-based pricing
- Cart rules with conditions
- Special offers (BOGO, Buy X Get Y)
- Gift products
- User role and customer history conditions
- Scheduling system
- Exclusion lists
- Quantity price tables
- Checkout deals with countdown
- GitHub auto-updater
