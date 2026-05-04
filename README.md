<img src="assets/adpc_logo_high.png" alt="ADPC" width="50%">

A WordPress plugin that implements the [Advanced Data Protection Control (ADPC)](https://www.dataprotectioncontrol.org/spec/).

- Serves a `/consent-requests.json` resource listing the purposes your site requests consent for
- Injects a `Link: <…>; rel="consent-requests"` HTTP response header on every page so ADPC-capable browsers can discover the consent resource
- Logs incoming `ADPC` headers from visitors and displays them in the WordPress admin

## Protocol flow

```
WordPress  ──[Link: </consent-requests.json>; rel="consent-requests"]──▶  Browser
              (on every page response)

Browser    ──[ADPC: consent="purpose_a purpose_b"]──────────────────────▶  WordPress
              (on subsequent requests, managed by browser extension)

Browser    ──[ADPC: withdraw="purpose_a"; consent="purpose_b"]──────────▶  WordPress
              (user revokes consent for one or more purposes)

Browser    ──[ADPC: withdraw=*]──────────────────────────────────────────▶  WordPress
              (user withdraws all previously given consent)
```

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+

## Admin pages

The plugin adds an **ADPC** top-level menu entry in the WordPress admin sidebar.

### ADPC → Settings

Configure consent requests and CMP integration.

**Consent Requests** - the purposes your site declares. Each entry has:

| Field | Description |
|---|---|
| **ID** | Machine-readable identifier (unreserved URI characters only, e.g. `analytics`) |
| **Description** | Human-readable text shown to the user by the browser extension |

Changes are reflected immediately in `/consent-requests.json`.

**CMP Integration** - when enabled, ADPC requests are automatically synced from the detected CMP on save, and incoming `ADPC` headers suppress the consent banner and pipe the consented categories back to the CMP. See [CMP support](#cmp-support) below.

### ADPC → Log

Paginated log of all incoming `ADPC` request headers from visitors. Shows timestamp, request URI, and the consented purpose IDs. The raw header value can be toggled via the **Show raw header** checkbox.

Use the **Clear Log** button to truncate the log table.

## CMP support

When CMP integration is enabled the plugin automatically detects and integrates with the following Consent Management Platforms:

| CMP | Installs | Plugin | Consent piping |
|---|---|---|---|
| [Complianz](https://wordpress.org/plugins/complianz-gdpr/) | 1M+ | `complianz-gdpr` | per category (own filter) |
| [Cookiebot](https://wordpress.org/plugins/cookiebot/) (Usercentrics) | 100K+ | `cookiebot` | per category (WP Consent API) |
| [GDPR Cookie Compliance](https://wordpress.org/plugins/gdpr-cookie-compliance/) (Moove) | 300K+ | `gdpr-cookie-compliance` | per category (WP Consent API) |
| [iubenda](https://wordpress.org/plugins/iubenda-cookie-law-solution/) | 200K+ | `iubenda-cookie-law-solution` | per category (WP Consent API) |

All listed CMPs also receive banner suppression. The `withdraw` directive maps to a deny signal per category; `withdraw=*` denies all configured categories not explicitly consented. Consent signals are additionally written to the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) on every request, benefiting any plugin that reads consent state from it.

When a CMP is detected, the **Settings** page shows the active CMP name, the last received `ADPC` header, and a per-category piping status widget. Consent requests are auto-imported from the CMP's configured categories each time settings are saved with integration enabled.

> **Note:** The list of supported CMPs is expanding. See the compliance requirements below.

### CMP compliance requirements

For a CMP to be supported, it must meet all of the following criteria:

| Requirement | What it means | Why it is needed |
|---|---|---|
| **PHP banner-suppression hook** | An `apply_filters()` call that prevents the banner from being rendered on a per-request basis | Allows ADPC-WP to hide the banner when the browser already carries a consent decision |
| **Writeable consent state** | Calls `wp_set_consent($category, 'allow'\|'deny')` (WP Consent API) or exposes an equivalent PHP filter | Allows ADPC-WP to pipe the browser's consent decision into the CMP so it enables or blocks scripts accordingly |
| **Per-category granularity** | Consent and denial can be applied individually per category, not only as an all-or-nothing grant | Required to honour partial consent (e.g. `ADPC: consent="statistics"; withdraw="marketing"`) |

CMPs that only satisfy banner suppression but not writeable consent state are not included, as suppressing the banner without actually applying the consent decision would leave the CMP in an inconsistent state.

## Endpoints

### `GET /consent-requests.json`

Returns the list of consent purposes configured in the admin. Served with `Cache-Control: no-store`.

**Example response:**
```json
{
  "consentRequests": [
    {
      "id": "analytics",
      "text": "Allow us to measure site usage with analytics."
    },
    {
      "id": "personalised_ads",
      "text": "Allow us to show personalised advertisements."
    }
  ]
}
```

Returns `{"consentRequests": []}` to signal compliance when no purposes are configured.

## Database

The plugin creates one table on activation:

**`{prefix}adpc_header_log`**

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT | Auto-increment primary key |
| `captured_at` | DATETIME | UTC timestamp of the request |
| `request_uri` | VARCHAR(512) | Request path |
| `adpc_raw` | TEXT | Raw `ADPC` header value |
| `consent_ids` | TEXT | Parsed comma-separated list of consented purpose IDs |

The table is left intact on deactivation and must be removed manually if no longer needed.

## Spec reference

[https://www.dataprotectioncontrol.org/spec/](https://www.dataprotectioncontrol.org/spec/)

## Also see
[https://github.com/Data-Protection-Control/ADPC-Wordpress](https://github.com/Data-Protection-Control/ADPC-Wordpress)
