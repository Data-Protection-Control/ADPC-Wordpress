<?php
/**
 * Plugin Name: ADPC-WP
 * Description: Implements the Advanced Data Protection Control (ADPC) protocol. Serves the consent-requests resource and logs incoming ADPC response headers from visitors.
 * Version:     1.0.0
 * Author:      ADPC
 * Spec:        https://www.dataprotectioncontrol.org/spec/
 */

defined( 'ABSPATH' ) || exit;

define( 'ADPC_WP_VERSION', '1.0.0' );
define( 'ADPC_WP_TABLE',   'adpc_header_log' );


// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'adpc_activate' );
function adpc_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . ADPC_WP_TABLE;
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        captured_at  DATETIME        NOT NULL,
        request_uri  VARCHAR(512)    NOT NULL DEFAULT '',
        adpc_raw     TEXT            NOT NULL,
        consent_ids  TEXT            NOT NULL,
        PRIMARY KEY (id),
        KEY captured_at (captured_at)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ---------------------------------------------------------------------------
// Consent-requests JSON endpoint  (/consent-requests.json)
// ---------------------------------------------------------------------------

add_action( 'init', 'adpc_serve_consent_requests', 1 );
function adpc_serve_consent_requests() {
    // Match the path regardless of site subfolder.
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) : '';
    if ( ! preg_match( '#/consent-requests\.json$#', $uri ) ) {
        return;
    }
    $items   = get_option( 'adpc_consent_requests', [] );
    $payload = [ 'consentRequests' => $items ];
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Cache-Control: no-store' );
    echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
    exit;
}

// ---------------------------------------------------------------------------
// Inject Link: <…>; rel="consent-requests" response header
// ---------------------------------------------------------------------------

add_action( 'send_headers', 'adpc_send_link_header' );
function adpc_send_link_header() {
    // Only on frontend page responses, not admin or cron.
    if ( is_admin() || wp_doing_cron() ) {
        return;
    }
    // Use replace=false so we append alongside WP's own Link headers.
    header( 'Link: <' . home_url( '/consent-requests.json' ) . '>; rel="consent-requests"', false );
}

// ---------------------------------------------------------------------------
// Capture & parse incoming ADPC request header
// ---------------------------------------------------------------------------

add_action( 'init', 'adpc_capture_header' );
function adpc_capture_header() {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron()
         || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return;
    }
    $raw = isset( $_SERVER['HTTP_ADPC'] ) ? trim( wp_unslash( $_SERVER['HTTP_ADPC'] ) ) : '';
    if ( $raw === '' ) {
        return;
    }

    $parsed = adpc_parse_header( $raw );

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . ADPC_WP_TABLE,
        [
            'captured_at'  => current_time( 'mysql', true ),
            'request_uri'  => isset( $_SERVER['REQUEST_URI'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 0, 512 ) : '',
            'adpc_raw'    => $raw,
            'consent_ids' => implode( ', ', $parsed['consent'] ),
        ],
        [ '%s', '%s', '%s', '%s' ]
    );
}

/**
 * Parse an ADPC header value into its constituent directives.
 *
 * Handles both spec formats:
 *   ADPC: consent="id1 id2", withdraw=*, object=direct-marketing  (quoted, space-sep values, comma-sep directives)
 *   ADPC: consent=id1,id2; withdraw=id3,id4                       (unquoted, comma-sep values, semicolon-sep directives)
 *
 * Returns array with keys: consent[], withdraw[], object[]
 */
function adpc_parse_header( $raw ) {
    $result = [ 'consent' => [], 'withdraw' => [], 'object' => [] ];

    // Split on semicolons OR on commas that are immediately followed by a directive keyword.
    // This handles both "; " (semicolon-separated) and ", key=" (comma-separated) directive formats.
    $directives = preg_split( '/\s*(?:;|,(?=[a-z][a-z0-9_-]*\s*=))\s*/i', $raw );

    foreach ( $directives as $directive ) {
        $directive = trim( $directive );
        if ( ! preg_match( '/^([a-z_-]+)\s*=\s*(.*)$/i', $directive, $m ) ) {
            continue;
        }
        $key = strtolower( trim( $m[1] ) );
        $val = trim( trim( wp_unslash( $m[2] ) ), '"' );

        // Values may be space-separated (quoted format) or comma-separated (unquoted format).
        $ids = array_values( array_filter( preg_split( '/[\s,]+/', $val ) ) );

        if ( $key === 'consent' ) {
            $result['consent'] = $ids;
        } elseif ( $key === 'withdraw' ) {
            $result['withdraw'] = ( $val === '*' ) ? [ '*' ] : $ids;
        } elseif ( $key === 'object' ) {
            $result['object'] = $ids;
        }
    }

    return $result;
}

/**
 * Returns the consented purpose IDs from the current request's ADPC header,
 * or an empty array if no ADPC header is present.
 *
 * @return string[]
 */
function adpc_get_request_consent_ids(): array {
    $raw = isset( $_SERVER['HTTP_ADPC'] ) ? trim( wp_unslash( $_SERVER['HTTP_ADPC'] ) ) : '';
    if ( $raw === '' ) {
        return [];
    }
    return adpc_parse_header( $raw )['consent'];
}

// ---------------------------------------------------------------------------
// CMP integration — pipe consent & suppress banners when ADPC header present
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'adpc_integrate_cmps', 1 );
function adpc_integrate_cmps(): void {
    if ( ! get_option( 'adpc_suppress_cmp', '1' ) ) {
        return;
    }

    $raw = isset( $_SERVER['HTTP_ADPC'] ) ? trim( wp_unslash( $_SERVER['HTTP_ADPC'] ) ) : '';
    if ( $raw === '' ) {
        return;
    }

    $parsed      = adpc_parse_header( $raw );
    $consent_ids = $parsed['consent'];
    $withdraw    = $parsed['withdraw']; // ['*'] for wildcard, or specific slugs

    if ( empty( $consent_ids ) && empty( $withdraw ) ) {
        return;
    }

    // ── Banner suppression ──────────────────────────────────────────────────
    // Applied for any ADPC header, regardless of which directives it contains.

    // Complianz.
    add_filter( 'cmplz_site_needs_cookiewarning', '__return_false', 999 );


    // Cookiebot (Cybot / Usercentrics) — disable via the banner-enabled option.
    add_filter( 'option_cookiebot-banner-enabled', '__return_zero', 999 );

    // GDPR Cookie Compliance (Moove Agency).
    add_filter( 'moove_gdpr_popup_html', '__return_empty_string', 999 );

    // iubenda — suppress by emptying the embed code before it is parsed.
    add_filter( 'iubenda_initial_output', '__return_empty_string', 999 );

    // ── Consent & withdraw piping ───────────────────────────────────────────

    $withdraw_all = in_array( '*', $withdraw, true );

    // Complianz — granular per-category via its own filter (not WP Consent API).
    add_filter( 'cmplz_has_consent', static function ( $consent, $category ) use ( $consent_ids, $withdraw, $withdraw_all ) {
        if ( in_array( $category, $consent_ids, true ) ) {
            return true;
        }
        if ( $withdraw_all || in_array( $category, $withdraw, true ) ) {
            return false;
        }
        return $consent;
    }, 999, 2 );

    // WP Consent API — allow consented categories, deny withdrawn ones.
    // Covers: Cookiebot, GDPR Cookie Compliance (Moove), iubenda, and any other
    // CMP with native WP Consent API support.
    if ( function_exists( 'wp_set_consent' ) ) {
        foreach ( $consent_ids as $category ) {
            wp_set_consent( sanitize_key( $category ), 'allow' );
        }

        if ( $withdraw_all ) {
            // withdraw=* — deny every configured category not explicitly consented.
            foreach ( array_keys( adpc_get_cmp_categories() ) as $cat ) {
                if ( ! in_array( $cat, $consent_ids, true ) ) {
                    wp_set_consent( sanitize_key( $cat ), 'deny' );
                }
            }
        } else {
            foreach ( $withdraw as $category ) {
                wp_set_consent( sanitize_key( $category ), 'deny' );
            }
        }
    }

    // Declare this plugin as WP Consent API compliant.
    add_filter( 'wp_consent_api_registered_' . plugin_basename( __FILE__ ), '__return_true' );
}

/**
 * Returns a list of [slug => description] for the active CMP's consent categories,
 * or an empty array if no supported CMP is detected.
 *
 * @return array<string,string>
 */
function adpc_get_cmp_categories(): array {
    $defaults = [
        'functional'           => 'Enable features that require cookies to work (e.g. forms, language preferences).',
        'statistics-anonymous' => 'Collect anonymous usage statistics to understand how the site is used.',
        'statistics'           => 'Collect usage statistics linked to your session.',
        'preferences'          => 'Remember your preferences such as language and region.',
        'marketing'            => 'Enable personalised advertising and marketing.',
    ];

    // WP Consent API — authoritative source when present.
    if ( function_exists( 'wp_set_consent' ) && has_filter( 'wp_consent_categories' ) ) {
        $slugs = apply_filters( 'wp_consent_categories', array_keys( $defaults ) );
        $out   = [];
        foreach ( (array) $slugs as $slug ) {
            $slug        = sanitize_key( $slug );
            $out[ $slug ] = $defaults[ $slug ] ?? '';
        }
        return $out;
    }

    // Complianz — read which categories are actually configured.
    if ( function_exists( 'cmplz_get_option' ) ) {
        $cats = [ 'functional' => $defaults['functional'] ];
        if ( function_exists( 'cmplz_uses_statistic_cookies' ) && cmplz_uses_statistic_cookies() ) {
            $cats['statistics'] = $defaults['statistics'];
        }
        if ( function_exists( 'cmplz_uses_preferences_cookies' ) && cmplz_uses_preferences_cookies() ) {
            $cats['preferences'] = $defaults['preferences'];
        }
        if ( function_exists( 'cmplz_uses_marketing_cookies' ) && cmplz_uses_marketing_cookies() ) {
            $cats['marketing'] = $defaults['marketing'];
        }
        return $cats;
    }

    // Cookiebot (Cybot / Usercentrics) — uses standard IAB TCF categories.
    if ( defined( 'CYBOT_COOKIEBOT_PLUGIN_DIR' ) ) {
        return [
            'functional'  => $defaults['functional'],
            'preferences' => $defaults['preferences'],
            'statistics'  => $defaults['statistics'],
            'marketing'   => $defaults['marketing'],
        ];
    }

    // GDPR Cookie Compliance (Moove Agency) — read which tabs are enabled.
    if ( class_exists( 'Moove_GDPR_Controller' ) || defined( 'MOOVE_GDPR_COOKIE_COMPLIANCE' ) ) {
        $opts = get_option( 'moove_gdpr_options', [] );
        $cats = [ 'necessary' => $defaults['functional'] ];
        if ( ! empty( $opts['moove_gdpr_third_party_enabled'] ) ) {
            $cats['thirdparty'] = $defaults['marketing'];
        }
        if ( ! empty( $opts['moove_gdpr_preferences_enabled'] ) ) {
            $cats['preferences'] = $defaults['preferences'];
        }
        if ( ! empty( $opts['moove_gdpr_statistics_enabled'] ) ) {
            $cats['statistics'] = $defaults['statistics'];
        }
        return $cats;
    }

    // iubenda — categories configured externally on the iubenda platform.
    if ( class_exists( 'iubenda' ) || defined( 'IUBENDA_PLUGIN_BASENAME' ) ) {
        return [
            'functional'  => $defaults['functional'],
            'preferences' => $defaults['preferences'],
            'statistics'  => $defaults['statistics'],
            'marketing'   => $defaults['marketing'],
        ];
    }

    return [];
}

/**
 * Returns the human-readable name of the first detected active CMP, or '' if none.
 */
function adpc_detected_cmp_name(): string {
    if ( function_exists( 'cmplz_get_option' ) )                                  return 'Complianz';
    if ( defined( 'CYBOT_COOKIEBOT_PLUGIN_DIR' ) )                                 return 'Cookiebot';
    if ( class_exists( 'Moove_GDPR_Controller' ) || defined( 'MOOVE_GDPR_COOKIE_COMPLIANCE' ) ) return 'GDPR Cookie Compliance';
    if ( class_exists( 'iubenda' ) || defined( 'IUBENDA_PLUGIN_BASENAME' ) )      return 'iubenda';
    return '';
}

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------

add_action( 'admin_head', 'adpc_admin_styles' );
function adpc_admin_styles() {
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'adpc' ) === false ) {
        return;
    }
    ?>
    <style>
    .adpc-wrap { max-width:900px; }
    .adpc-card { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:24px 28px; margin-bottom:16px; }
    .adpc-card-title { margin:0 0 16px; padding:0 0 14px; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#646970; border-bottom:1px solid #f0f0f1; }
    .adpc-card p:first-of-type { margin-top:0; }
    .adpc-card p:last-of-type { margin-bottom:0; }
    .adpc-card table.widefat { border:none; box-shadow:none; }
    .adpc-card table.widefat th, .adpc-card table.widefat td { padding:10px 12px; }
    .adpc-card table.striped>tbody>:nth-child(odd) { background:#f9f9f9; }
    .adpc-card table thead th { background:#f6f7f7; border-bottom:1px solid #e5e5e5; font-weight:600; }
    .adpc-log-toolbar { display:flex; align-items:center; gap:16px; margin-bottom:16px; }
    .adpc-toggle { position:relative; display:inline-block; width:44px; height:24px; }
    .adpc-toggle input { opacity:0; width:0; height:0; }
    .adpc-toggle-slider { position:absolute; inset:0; background:#ccc; border-radius:24px; cursor:pointer; transition:.2s; }
    .adpc-toggle-slider:before { content:''; position:absolute; width:18px; height:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; }
    .adpc-toggle input:checked + .adpc-toggle-slider { background:#2271b1; }
    .adpc-toggle input:checked + .adpc-toggle-slider:before { transform:translateX(20px); }
    #adpc-cr-wrapper.adpc-cmp-managed { opacity:.45; pointer-events:none; cursor:default; }
    .adpc-status-grid { display:grid; grid-template-columns:160px 1fr; gap:0; }
    .adpc-status-grid dt { font-weight:600; font-size:12px; color:#646970; padding:8px 0; border-bottom:1px solid #f0f0f1; }
    .adpc-status-grid dd { margin:0; padding:8px 0; border-bottom:1px solid #f0f0f1; }
    .adpc-status-grid dt:last-of-type, .adpc-status-grid dd:last-of-type { border-bottom:none; }
    .adpc-pipe-tag { display:inline-flex; align-items:center; gap:4px; background:#f0f0f1; border-radius:4px; padding:2px 8px; font-size:12px; margin:2px 4px 2px 0; }
    .adpc-pipe-tag.piped { background:#edfaed; color:#1a7a1a; }
    .adpc-pipe-tag.not-piped { color:#999; }
    </style>
    <?php
}

add_action( 'admin_menu', 'adpc_admin_menu' );
function adpc_admin_menu() {
    // Position just after Complianz (40) when active, otherwise use a generic position.
    $position = defined( 'CMPLZ_MAIN_MENU_POSITION' ) ? CMPLZ_MAIN_MENU_POSITION + 0.1 : 80;
    add_menu_page(
        'ADPC',
        'ADPC',
        'manage_options',
        'adpc-settings',
        'adpc_settings_page',
        plugins_url( 'assets/icon16.png', __FILE__ ),
        $position
    );
    add_submenu_page( 'adpc-settings', 'ADPC Settings', 'Settings', 'manage_options', 'adpc-settings', 'adpc_settings_page' );
    add_submenu_page( 'adpc-settings', 'ADPC Log', 'Log', 'manage_options', 'adpc-log', 'adpc_log_page' );
}

// ---------------------------------------------------------------------------
// Settings page
// ---------------------------------------------------------------------------

function adpc_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['adpc_save'] ) ) {
        check_admin_referer( 'adpc_save' );

        $integration_enabled = isset( $_POST['adpc_suppress_cmp'] );
        update_option( 'adpc_suppress_cmp', $integration_enabled ? '1' : '0' );

        // Auto-import from CMP when integration is enabled and a CMP is active.
        if ( $integration_enabled ) {
            $cats = adpc_get_cmp_categories();
            if ( ! empty( $cats ) ) {
                $auto_items = [];
                foreach ( $cats as $slug => $desc ) {
                    $auto_items[] = [ 'id' => $slug, 'text' => $desc ];
                }
                update_option( 'adpc_consent_requests', $auto_items );
                echo '<div class="notice notice-success"><p>Settings saved. Purposes synced from ' . esc_html( adpc_detected_cmp_name() ) . '.</p></div>';
            } else {
                // No CMP — save manually-entered purposes.
                $ids   = isset( $_POST['adpc_cr_id'] )   ? (array) $_POST['adpc_cr_id']   : [];
                $texts = isset( $_POST['adpc_cr_text'] ) ? (array) $_POST['adpc_cr_text'] : [];
                $items = [];
                foreach ( $ids as $i => $id ) {
                    $id = sanitize_key( $id );
                    if ( $id !== '' ) {
                        $items[] = [ 'id' => $id, 'text' => sanitize_text_field( $texts[ $i ] ?? '' ) ];
                    }
                }
                update_option( 'adpc_consent_requests', $items );
                echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
            }
        } else {
            // Integration off — save manually-entered purposes.
            $ids   = isset( $_POST['adpc_cr_id'] )   ? (array) $_POST['adpc_cr_id']   : [];
            $texts = isset( $_POST['adpc_cr_text'] ) ? (array) $_POST['adpc_cr_text'] : [];
            $items = [];
            foreach ( $ids as $i => $id ) {
                $id = sanitize_key( $id );
                if ( $id !== '' ) {
                    $items[] = [ 'id' => $id, 'text' => sanitize_text_field( $texts[ $i ] ?? '' ) ];
                }
            }
            update_option( 'adpc_consent_requests', $items );
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }
    }

    $items       = get_option( 'adpc_consent_requests', [] );
    $json_url    = home_url( '/consent-requests.json' );
    $cmp_name    = adpc_detected_cmp_name();
    $suppress_on = get_option( 'adpc_suppress_cmp', '1' ) === '1';
    $logo_url    = plugins_url( 'assets/adpc_logo_high.png', __FILE__ );

    if ( $suppress_on ) {
        global $wpdb;
        $last            = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . ADPC_WP_TABLE . " WHERE consent_ids != '' ORDER BY id DESC LIMIT %d", 1
        ) );
        $last_ids        = $last ? array_map( 'trim', explode( ',', $last->consent_ids ) ) : [];
        $configured_cats = array_keys( adpc_get_cmp_categories() );
    }
    ?>
    <div class="wrap adpc-wrap">

        <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px">
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="ADPC" style="height:40px;width:auto">
            <div>
                <h1 style="margin:0;padding:0;font-size:20px">ADPC Settings</h1>
                <p style="margin:2px 0 0;color:#646970;font-size:13px">
                    <a href="https://www.dataprotectioncontrol.org/spec/" target="_blank">Advanced Data Protection Control</a> &mdash;
                    <a href="<?php echo esc_url( $json_url ); ?>" target="_blank"><?php echo esc_html( $json_url ); ?></a>
                </p>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'adpc_save' ); ?>

            <div class="adpc-card">
                <h2 class="adpc-card-title">CMP Integration</h2>
                <p style="display:flex;align-items:flex-start;gap:14px;margin-bottom:<?php echo $suppress_on ? '20px' : '0'; ?>">
                    <label class="adpc-toggle" style="flex-shrink:0;margin-top:2px">
                        <input type="checkbox" name="adpc_suppress_cmp" value="1"<?php checked( $suppress_on ); ?>>
                        <span class="adpc-toggle-slider"></span>
                    </label>
                    <span style="color:#3c434a">
                        <?php if ( $cmp_name !== '' ) : ?>
                            <strong><?php echo esc_html( $cmp_name ); ?> detected.</strong>
                            When enabled, ADPC requests are automatically synced from <?php echo esc_html( $cmp_name ); ?> on save.
                            When a visitor's browser sends an <code>ADPC</code> header, the consent banner is suppressed and the consented categories are piped to <?php echo esc_html( $cmp_name ); ?>.
                        <?php else : ?>
                            When a visitor's browser sends an <code>ADPC</code> header, suppress the cookie consent banner and pipe the consent decision to any active CMP plugin (Complianz, Cookiebot, GDPR Cookie Compliance, iubenda, WP Consent API).
                        <?php endif; ?>
                    </span>
                </p>

                <?php if ( $suppress_on ) : ?>
                <div style="border-top:1px solid #f0f0f1;padding-top:16px">
                    <dl class="adpc-status-grid">
                        <dt>Last consent header</dt>
                        <dd>
                            <?php if ( $last ) : ?>
                                <code style="word-break:break-all"><?php echo esc_html( $last->adpc_raw ); ?></code><br>
                                <span style="color:#646970;font-size:12px"><?php echo esc_html( $last->captured_at ); ?> &mdash; <code><?php echo esc_html( $last->request_uri ); ?></code></span>
                            <?php else : ?>
                                <span style="color:#999">No entries logged yet.</span>
                            <?php endif; ?>
                        </dd>
                        <?php if ( $last_ids && $cmp_name !== '' && ! empty( $configured_cats ) ) : ?>
                        <dt><?php echo esc_html( $cmp_name ); ?> piping</dt>
                        <dd>
                            <?php foreach ( $configured_cats as $cat ) :
                                $piped = in_array( $cat, $last_ids, true ); ?>
                            <span class="adpc-pipe-tag <?php echo $piped ? 'piped' : 'not-piped'; ?>">
                                <?php echo $piped ? '&#10003;' : '&#10007;'; ?> <?php echo esc_html( $cat ); ?>
                            </span>
                            <?php endforeach; ?>
                        </dd>
                        <?php endif; ?>
                    </dl>
                </div>
                <?php endif; ?>
            </div>

            <div class="adpc-card" id="adpc-cr-card">
                <h2 class="adpc-card-title" id="adpc-cr-heading">Consent Requests</h2>
                <div id="adpc-cr-wrapper">
                    <p style="color:#646970;margin-bottom:16px">
                        Listed in <a href="<?php echo esc_url( $json_url ); ?>" target="_blank"><code>/consent-requests.json</code></a> and advertised to browsers via the <code>Link</code> response header.
                    </p>
                    <table class="widefat striped" id="adpc-cr-table">
                        <thead>
                            <tr>
                                <th style="width:220px">ID <small style="font-weight:400;color:#646970">(unreserved URI chars)</small></th>
                                <th>Description shown to user</th>
                                <th style="width:80px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $items as $item ) : ?>
                            <tr>
                                <td><input type="text" name="adpc_cr_id[]" value="<?php echo esc_attr( $item['id'] ); ?>" placeholder="analytics" style="width:100%"></td>
                                <td><input type="text" name="adpc_cr_text[]" value="<?php echo esc_attr( $item['text'] ); ?>" placeholder="Allow us to…" style="width:100%"></td>
                                <td><button type="button" class="button adpc-remove-row">Remove</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:12px"><button type="button" class="button" id="adpc-add-row">+ Add Request</button></p>
                </div>
            </div>

            <p>
                <input type="submit" name="adpc_save" class="button button-primary" value="Save Settings">
            </p>
        </form>

    </div>
    <script>
    (function(){
        var toggle  = document.querySelector('[name="adpc_suppress_cmp"]');
        var card    = document.getElementById('adpc-cr-card');
        var cmpName = <?php echo wp_json_encode( $cmp_name ); ?>;
        function updateCrState() {
            var hide = toggle && toggle.checked && cmpName;
            if ( card ) card.style.display = hide ? 'none' : '';
        }
        if ( toggle ) {
            toggle.addEventListener('change', updateCrState);
            updateCrState();
        }

        var addBtn = document.getElementById('adpc-add-row');
        if ( addBtn ) {
            addBtn.addEventListener('click', function(){
                var tbody = document.querySelector('#adpc-cr-table tbody');
                var tr    = document.createElement('tr');
                tr.innerHTML = '<td><input type="text" name="adpc_cr_id[]" placeholder="analytics" style="width:100%"></td>'
                             + '<td><input type="text" name="adpc_cr_text[]" placeholder="Allow us to…" style="width:100%"></td>'
                             + '<td><button type="button" class="button adpc-remove-row">Remove</button></td>';
                tbody.appendChild(tr);
            });
        }
        document.addEventListener('click', function(e){
            if ( e.target.classList.contains('adpc-remove-row') ) {
                e.target.closest('tr').remove();
            }
        });
    })();
    </script>
    <?php
}

// ---------------------------------------------------------------------------
// Log page — incoming ADPC request headers
// ---------------------------------------------------------------------------

function adpc_log_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . ADPC_WP_TABLE;

    if ( isset( $_POST['adpc_clear_log'] ) ) {
        check_admin_referer( 'adpc_clear_log' );
        $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
        echo '<div class="notice notice-success"><p>Log cleared.</p></div>';
    }

    $per_page     = 25;
    $current_page = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
    $offset       = ( $current_page - 1 ) * $per_page;
    $total        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
    $rows         = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} ORDER BY captured_at DESC LIMIT %d OFFSET %d", // phpcs:ignore
        $per_page, $offset
    ) );
    $total_pages  = max( 1, ceil( $total / $per_page ) );
    $logo_url     = plugins_url( 'assets/adpc_logo_high.png', __FILE__ );
    ?>
    <div class="wrap adpc-wrap">

        <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px">
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="ADPC" style="height:40px;width:auto">
            <div>
                <h1 style="margin:0;padding:0;font-size:20px">ADPC Log</h1>
                <p style="margin:2px 0 0;color:#646970;font-size:13px">
                    Incoming <code>ADPC</code> headers from visitors &mdash; <?php echo (int) $total; ?> <?php echo $total === 1 ? 'entry' : 'entries'; ?>
                </p>
            </div>
        </div>

        <div class="adpc-card" style="padding:16px 28px">
            <div class="adpc-log-toolbar">
                <form method="post" style="margin:0">
                    <?php wp_nonce_field( 'adpc_clear_log' ); ?>
                    <input type="submit" name="adpc_clear_log" class="button button-secondary" value="Clear Log"
                           onclick="return confirm('Clear all log entries?');">
                </form>
                <label style="font-weight:500;font-size:13px;cursor:pointer">
                    <input type="checkbox" id="adpc-show-raw" style="margin-right:5px">
                    Show raw header
                </label>
            </div>
        </div>

        <?php if ( empty( $rows ) ) : ?>
        <div class="adpc-card">
            <p style="color:#646970;margin:0">No entries yet. Make sure <a href="<?php echo esc_url( menu_page_url( 'adpc-settings', false ) ); ?>">consent requests are configured</a>, then visit the frontend with an ADPC-capable browser.</p>
        </div>
        <?php else : ?>
        <div class="adpc-card" style="padding:0;overflow:hidden">
            <table class="widefat striped" id="adpc-log-table" style="border-radius:8px">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th style="width:155px">Captured At</th>
                        <th>Request URI</th>
                        <th class="adpc-col-raw" style="display:none">Raw ADPC Header</th>
                        <th style="width:220px">Consent</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td style="color:#999"><?php echo (int) $row->id; ?></td>
                        <td style="white-space:nowrap;color:#646970;font-size:12px"><?php echo esc_html( $row->captured_at ); ?></td>
                        <td><code style="font-size:12px"><?php echo esc_html( $row->request_uri ); ?></code></td>
                        <td class="adpc-col-raw" style="display:none"><code style="font-size:12px;word-break:break-all"><?php echo esc_html( $row->adpc_raw ); ?></code></td>
                        <td><?php echo $row->consent_ids !== '' ? '<code style="font-size:12px">' . esc_html( $row->consent_ids ) . '</code>' : '<span style="color:#ccc">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ( $total_pages > 1 ) : ?>
            <div style="padding:12px 28px;border-top:1px solid #f0f0f1">
                <?php
                echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%', menu_page_url( 'adpc-log', false ) ),
                    'format'    => '',
                    'current'   => $current_page,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    <script>
    (function(){
        var cb  = document.getElementById('adpc-show-raw');
        if ( ! cb ) return;
        var key = 'adpc_show_raw';
        cb.checked = localStorage.getItem( key ) === '1';
        function toggle() {
            var show = cb.checked;
            localStorage.setItem( key, show ? '1' : '0' );
            document.querySelectorAll('.adpc-col-raw').forEach(function(el){
                el.style.display = show ? '' : 'none';
            });
        }
        toggle();
        cb.addEventListener('change', toggle);
    })();
    </script>
    <?php
}
