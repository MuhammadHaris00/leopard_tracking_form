# Leopard Tracking Form

A WordPress plugin that lets customers track **Leopards Courier (Pakistan)** shipments via a frontend shortcode.

## Features

- Track one or multiple CN (consignment) numbers via the Leopards Courier merchant API.
- Shortcodes:
  - `[leopard_tracking_form]` — tracking form
  - `[leopard_tracking_result]` — tracking result page
- AJAX mode for inline results without page reload (with redirect fallback).
- Auto-creates a `leopard-tracking-result` result page on activation.
- Separate CSS/JS assets, enqueued only on pages using the shortcodes.
- Editable templates (`templates/form.php`, `templates/result.php`) overridable from your theme at `your-theme/leopard-tracking/`.
- Settings page under **Settings → Leopard Tracking** for API key, password, test/staging mode, result page, cache duration.
- Reuses credentials from a Leopards Courier WooCommerce add-on when found.
- Optional license-gated tracking proxy (API credentials stay on your license server, never on client sites).

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Installation

1. Download the plugin ZIP (see [Releases](https://github.com/Muhammad-Haris/leopard_tracking_form/releases)).
2. Go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate the plugin.
4. Visit **Settings → Leopard Tracking** and enter your Leopards Courier API credentials.
5. Add `[leopard_tracking_form]` to any page or post where you want the tracking form.

## Usage

### Form shortcode

```
[leopard_tracking_form placeholder="Enter tracking number" button="Track Shipment" show_result_inline="yes"]
```

Attributes:

- `placeholder` — input placeholder text.
- `button` — submit button text.
- `show_result_inline` — `yes` to show AJAX result inline; `no` to redirect to the result page.

### Result shortcode

Place `[leopard_tracking_result]` on your tracking result page (auto-created on activation as `leopard-tracking-result`).

The result page reads the tracking number from the `?ltf_cn=` query parameter.

### Templates

Copy `templates/form.php` and/or `templates/result.php` into `your-theme/leopard-tracking/` to override the default markup.

## License

None — provided as-is.

## Author

Muhammad Haris
