<?php
/**
 * Plugin Name:  Hostorio AI Chatbot
 * Description:  Adds the Hostorio AI support chat widget to your WordPress site.
 * Version:      0.1.0
 * Requires PHP: 7.4
 * License:      GPL-2.0-or-later
 *
 * Installation
 * ------------
 * Copy this single file into wp-content/plugins/ and activate it, then set your
 * chatbot URL under Settings → Hostorio Chatbot.
 *
 * Two ways to place the widget:
 *   - Site-wide: leave "Load on every page" ticked (the default).
 *   - Specific pages: untick it and use the [hostorio_chat] shortcode.
 *
 * Note on PHP version: the chatbot itself needs PHP 8.1, but this shim only
 * prints a script tag and runs inside WordPress, which still supports 7.4 —
 * so it is written to that floor rather than the application's.
 */

if (!defined('ABSPATH')) {
    exit; // Direct access.
}

final class Hostorio_Chatbot_Plugin
{
    const OPTION = 'hostorio_chatbot_settings';

    /** @var bool guards against the shortcode and the site-wide hook both firing */
    private $printed = false;

    public static function boot()
    {
        $plugin = new self();

        add_action('admin_menu', array($plugin, 'add_settings_page'));
        add_action('admin_init', array($plugin, 'register_settings'));
        add_action('wp_footer', array($plugin, 'maybe_print_widget'), 100);
        add_shortcode('hostorio_chat', array($plugin, 'shortcode'));
    }

    /**
     * @return array<string, mixed>
     */
    private function settings()
    {
        $defaults = array(
            'base_url'   => '',
            'accent'     => '',
            'position'   => 'bottom-right',
            'everywhere' => 1,
            'identify'   => 0,
        );

        $saved = get_option(self::OPTION, array());

        return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function maybe_print_widget()
    {
        $settings = $this->settings();

        if (empty($settings['everywhere'])) {
            return;
        }

        $this->print_widget($settings);
    }

    public function shortcode($atts)
    {
        $atts = shortcode_atts(
            array('accent' => '', 'position' => ''),
            is_array($atts) ? $atts : array(),
            'hostorio_chat'
        );

        $settings = $this->settings();

        if (!empty($atts['accent'])) {
            $settings['accent'] = $atts['accent'];
        }

        if (!empty($atts['position'])) {
            $settings['position'] = $atts['position'];
        }

        // The widget attaches itself to <body>, so the shortcode outputs the
        // loader rather than markup at the shortcode's position.
        ob_start();
        $this->print_widget($settings);

        return ob_get_clean();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function print_widget($settings)
    {
        if ($this->printed) {
            return; // The script guards against this too, but do not emit it twice.
        }

        $base = rtrim(trim((string) $settings['base_url']), '/');

        if ($base === '') {
            return;
        }

        $this->printed = true;

        $attributes = array(
            'src'           => $base . '/widget/widget.js',
            'data-endpoint' => $base . '/api/chat',
            'data-position' => $settings['position'] === 'bottom-left' ? 'bottom-left' : 'bottom-right',
        );

        if (!empty($settings['accent'])) {
            $attributes['data-accent'] = $settings['accent'];
        }

        $token = $this->identity_token($settings);

        if ($token !== '') {
            $attributes['data-token'] = $token;
        }

        $rendered = '';

        foreach ($attributes as $name => $value) {
            $rendered .= ' ' . $name . '="' . esc_attr($value) . '"';
        }

        echo '<script' . $rendered . ' defer></script>' . "\n";
    }

    /**
     * Mint a signed identity token for the logged-in visitor.
     *
     * Only meaningful when WordPress and the chatbot share a user identity —
     * usually a WHMCS bridge that stores the client id on the WP user. Nothing
     * is emitted otherwise, and the chatbot treats the visitor as anonymous.
     *
     * The token is generated *server-side* here. Never let the browser supply a
     * customer id: the chatbot rejects unsigned claims precisely because they
     * would otherwise expose one customer's billing data to another.
     *
     * @param array<string, mixed> $settings
     * @return string
     */
    private function identity_token($settings)
    {
        if (empty($settings['identify']) || !is_user_logged_in()) {
            return '';
        }

        /**
         * Filter: return the WHMCS client id for the current WordPress user.
         *
         * Example, for a bridge that stores it in user meta:
         *
         *   add_filter('hostorio_chatbot_client_id', function () {
         *       return (int) get_user_meta(get_current_user_id(), 'whmcs_client_id', true);
         *   });
         */
        $clientId = (int) apply_filters('hostorio_chatbot_client_id', 0);

        if ($clientId < 1) {
            return '';
        }

        /**
         * Filter: return a signed token for that client id.
         *
         * Generate it with the chatbot's own IdentityToken class when the two
         * applications sit on the same server:
         *
         *   add_filter('hostorio_chatbot_token', function ($token, $clientId) {
         *       require_once '/home/user/chatbot/bootstrap.php';
         *       return Hostorio\Context\IdentityToken::issue($clientId);
         *   }, 10, 2);
         */
        return (string) apply_filters('hostorio_chatbot_token', '', $clientId);
    }

    // ── Settings screen ──────────────────────────────────────────────────────

    public function register_settings()
    {
        register_setting('hostorio_chatbot', self::OPTION, array(
            'sanitize_callback' => array($this, 'sanitize'),
        ));
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize($input)
    {
        $input = is_array($input) ? $input : array();

        return array(
            'base_url'   => esc_url_raw(trim((string) (isset($input['base_url']) ? $input['base_url'] : ''))),
            'accent'     => sanitize_hex_color((string) (isset($input['accent']) ? $input['accent'] : '')),
            'position'   => (isset($input['position']) && $input['position'] === 'bottom-left')
                ? 'bottom-left' : 'bottom-right',
            'everywhere' => empty($input['everywhere']) ? 0 : 1,
            'identify'   => empty($input['identify']) ? 0 : 1,
        );
    }

    public function add_settings_page()
    {
        add_options_page(
            'Hostorio Chatbot',
            'Hostorio Chatbot',
            'manage_options',
            'hostorio-chatbot',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->settings();
        ?>
        <div class="wrap">
            <h1>Hostorio AI Chatbot</h1>

            <form method="post" action="options.php">
                <?php settings_fields('hostorio_chatbot'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hoai_base">Chatbot URL</label></th>
                        <td>
                            <input type="url" id="hoai_base" class="regular-text"
                                   name="<?php echo esc_attr(self::OPTION); ?>[base_url]"
                                   value="<?php echo esc_attr($settings['base_url']); ?>"
                                   placeholder="https://support.example.com">
                            <p class="description">
                                Where the chatbot is installed, without a trailing slash.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hoai_accent">Accent colour</label></th>
                        <td>
                            <input type="text" id="hoai_accent" class="regular-text"
                                   name="<?php echo esc_attr(self::OPTION); ?>[accent]"
                                   value="<?php echo esc_attr($settings['accent']); ?>"
                                   placeholder="#2563eb">
                            <p class="description">Leave blank to use the chatbot's own setting.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Position</th>
                        <td>
                            <label>
                                <input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[position]"
                                       value="bottom-right" <?php checked($settings['position'], 'bottom-right'); ?>>
                                Bottom right
                            </label><br>
                            <label>
                                <input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[position]"
                                       value="bottom-left" <?php checked($settings['position'], 'bottom-left'); ?>>
                                Bottom left
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Placement</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[everywhere]"
                                       value="1" <?php checked($settings['everywhere'], 1); ?>>
                                Load on every page
                            </label>
                            <p class="description">
                                Untick to place it only where you use the
                                <code>[hostorio_chat]</code> shortcode.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Signed-in customers</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[identify]"
                                       value="1" <?php checked($settings['identify'], 1); ?>>
                                Pass a signed identity token for logged-in users
                            </label>
                            <p class="description">
                                Lets the assistant see the customer's own services, invoices and
                                tickets. Requires the <code>hostorio_chatbot_client_id</code> and
                                <code>hostorio_chatbot_token</code> filters to be implemented —
                                see the comments in this plugin file.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

Hostorio_Chatbot_Plugin::boot();
