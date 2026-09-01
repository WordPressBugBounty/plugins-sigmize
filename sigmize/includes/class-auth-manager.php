<?php

/**
 * Authentication Manager class
 *
 * @package Sigmize
 */

namespace Sigmize;

use Sigmize\Encryption;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Authentication Manager class
 * Handles OAuth authentication with the SaaS platform
 */
class Auth_Manager
{

    /**
     * Option name for storing the auth token
     */
    const TOKEN_OPTION = 'sigmize_auth_token';

    /**
     * Option name for storing the bearer token
     */
    const BEARER_TOKEN_OPTION = 'sigmize_bearer_token';

    /**
     * Option name for storing the webhook secret
     *
     * @since 0.0.11
     */
    const WEBHOOK_SECRET_OPTION = 'sigmize_webhook_secret';

    /**
     * SaaS authentication URL
     */
    const SAAS_AUTH_URL = SIGMIZE_SAAS_BASE_URL . '/connect';

    /**
     * SaaS token exchange URL
     */
    const TOKEN_EXCHANGE_URL = SIGMIZE_SAAS_API_BASE_URL . '/api/v1/connections/exchange';

    /**
     * SaaS one-click provisioning URL
     *
     * @since 1.1.2
     */
    const PROVISION_URL = SIGMIZE_SAAS_API_BASE_URL . '/api/v1/connections/provision';

    /**
     * Constructor
     *
     * @since 0.0.1
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        add_action('admin_init', array($this, 'check_authentication'));
    }

    /**
     * Check if user is authenticated
     *
     * @since 0.0.1
     *
     * @return bool
     */
    public function is_authenticated()
    {
        $token = $this->get_bearer_token();
        return ! empty($token);
    }

    /**
     * Get the stored bearer token
     *
     * @since 0.0.1
     *
     * @return string|false
     */
    public function get_bearer_token()
    {
        $encrypted_token = get_option(self::BEARER_TOKEN_OPTION, false);

        if ($encrypted_token) {
            return ( new Encryption() )->decrypt($encrypted_token);
        }

        return false;
    }


    /**
     * Check if there's an authentication error
     *
     * @since 0.0.1
     *
     * @return bool
     */
    public function has_auth_error()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback, nonce not applicable for external redirects
        return isset($_GET['auth_error']) && sanitize_text_field(wp_unslash($_GET['auth_error'])) === '1';
    }

    /**
     * Store the bearer token
     *
     * @since 0.0.11
     *
     * @param string $token Bearer token.
     * @return bool
     */
    private function store_bearer_token($token)
    {
        if (empty($token)) {
            return false;
        }

        // Validate token character set (Sanctum format: {id}|{token}, JWT-safe chars).
        if (! preg_match('/^[A-Za-z0-9\-_\.\/\+\=\|]+$/', $token)) {
            return false;
        }

        // Store the encrypted token in the database. Not autoloaded: it is a
        // server-side credential only needed during SaaS API and webhook calls,
        // so it should not load on anonymous front-end requests.
        return update_option(self::BEARER_TOKEN_OPTION, ( new Encryption() )->encrypt($token), false);
    }


    /**
     * Create a Sigmize account for this site and connect it, in one call.
     *
     * The OAuth flow assumes the user already has an account and can complete
     * a redirect in wp-admin. This is the path for someone with neither — the
     * setup checklist button — so the site introduces itself instead:
     * admin_email and the site name are all the platform gets.
     *
     * The platform calls back to /verify before creating anything, which is
     * how it knows the caller really is the site it claims to be.
     *
     * @since 1.1.2
     *
     * @param string $source Product whose onboarding sent this, if reported.
     * @return true|\WP_Error
     */
    public function provision_account($source = '')
    {
        $body = array(
            'email'          => sanitize_email((string) get_option('admin_email')),
            // The REST namespace root, matching exactly what exchange_token()
            // sends, so a provisioned connection is indistinguishable from an
            // OAuth-connected one.
            'site_url'       => rest_url('sigmize/v1/'),
            'site_name'      => get_bloginfo('name'),
            'plugin_version' => SIGMIZE_VERSION,
            'locale'         => get_locale(),
            // Forwarded as reported. The platform allowlists it and defaults
            // anything empty or unrecognised, so a second default here would
            // just be somewhere else for the two to disagree.
            'source'         => $source,
        );

        if (empty($body['email'])) {
            return new \WP_Error(
                'invalid_admin_email',
                __('This site has no valid administrator email address.', 'sigmize'),
                array('status' => 422)
            );
        }

        $response = wp_remote_post(
            self::PROVISION_URL,
            array(
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode($body),
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            return new \WP_Error(
                'provision_request_failed',
                __('Could not reach Sigmize. Please try again.', 'sigmize'),
                array('status' => 502)
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 && $code !== 201) {
            // Pass the platform's own code through. The checklist needs to tell
            // "this email already has an account, sign in instead" apart from a
            // genuine failure, and flattening them loses the only actionable
            // thing we know.
            $error_code = is_array($result) && ! empty($result['error_code'])
                ? sanitize_key((string) $result['error_code'])
                : 'provision_failed';
            $message = is_array($result) && ! empty($result['message'])
                ? sanitize_text_field((string) $result['message'])
                : __('Sigmize could not set up your account.', 'sigmize');

            return new \WP_Error($error_code, $message, array('status' => $code));
        }

        if (! is_array($result) || empty($result['data'])) {
            return new \WP_Error(
                'invalid_provision_response',
                __('Sigmize returned an unexpected response.', 'sigmize'),
                array('status' => 502)
            );
        }

        return $this->store_credentials($result['data']);
    }

    /**
     * Persist the credentials a connection needs.
     *
     * Writes through the same helpers the OAuth exchange uses, so encryption
     * and token validation stay in one place rather than drifting into a
     * second implementation.
     *
     * @since 1.1.2
     *
     * @param array $data Payload from the platform.
     * @return true|\WP_Error
     */
    private function store_credentials($data)
    {
        $access_token = isset($data['access_token']) ? (string) $data['access_token'] : '';
        $workspace_uuid = isset($data['workspace_uuid']) ? (string) $data['workspace_uuid'] : '';

        // Both are required: the admin UI decides it is connected from the
        // bearer token, while the front-end SDK and the Abilities API decide
        // from the workspace UUID. Storing one without the other leaves the
        // site connected in one place and not the other.
        if ('' === $access_token || '' === $workspace_uuid) {
            return new \WP_Error(
                'incomplete_credentials',
                __('Sigmize did not return usable credentials.', 'sigmize'),
                array('status' => 502)
            );
        }

        // Persisting the token is what makes the connection usable, so a
        // failure here is fatal — never report success without one.
        if (! $this->store_bearer_token($access_token)) {
            return new \WP_Error(
                'credential_storage_failed',
                __('Could not save the Sigmize connection.', 'sigmize'),
                array('status' => 500)
            );
        }

        update_option('sigmize_workspace_uuid', sanitize_text_field($workspace_uuid));

        if (! empty($data['connection_id'])) {
            update_option('sigmize_connection_id', sanitize_text_field((string) $data['connection_id']));
        }

        if (! empty($data['webhook_secret'])) {
            update_option(
                self::WEBHOOK_SECRET_OPTION,
                ( new Encryption() )->encrypt((string) $data['webhook_secret'])
            );
        }

        return true;
    }

    /**
     * Whether this site already has a connection stored.
     *
     * @since 1.1.2
     *
     * @return bool
     */
    public static function has_connection()
    {
        return ! empty(get_option(self::BEARER_TOKEN_OPTION))
            || ! empty(get_option('sigmize_workspace_uuid'));
    }

    /**
     * Get the OAuth callback URL
     *
     * @since 0.0.1
     *
     * @return string
     */
    public function get_callback_url()
    {
        return admin_url('admin.php?page=sigmize-dashboard');
    }

    /**
     * Get the authentication URL
     *
     * @since 0.0.1
     *
     * @return string
     */
    public function get_auth_url()
    {
        $callback_url = $this->get_callback_url();
        $state = $this->generate_oauth_state();

        $params = array(
            'oauth_url' => urlencode($callback_url),
        );

        // Include state parameter if generated successfully
        // Note: Don't urlencode state - add_query_arg() handles encoding automatically
        if ($state) {
            $params['state'] = $state;
        }

        return add_query_arg($params, self::SAAS_AUTH_URL);
    }

    /**
     * Handle OAuth callback
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function handle_oauth_callback()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback from external SaaS, state parameter used for CSRF protection

        // First check if we have oauth_token in the URL
        // Handle both proper format and malformed URLs
        $oauth_token = null;

        // Check standard $_GET parameter
        if (isset($_GET['oauth_token'])) {
            $oauth_token = sanitize_text_field(wp_unslash($_GET['oauth_token']));
        } else {
            // Handle malformed URL with double question mark
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            if ($request_uri && preg_match('/[?&]oauth_token=([^&]+)/', $request_uri, $matches)) {
                $oauth_token = sanitize_text_field($matches[1]);
            }
        }

        // If no token found, return early
        if (! $oauth_token) {
            return;
        }

        // Check if we're on our plugin page
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (! $page) {
            // Try to extract page from URL if not in $_GET
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            if ($request_uri && preg_match('/page=([^&?]+)/', $request_uri, $matches)) {
                $page = sanitize_text_field($matches[1]);
            }
        }

        if (! $page || strpos($page, 'sigmize') !== 0) {
            return;
        }

        // Validate state parameter for CSRF protection
        $state = null;
        if (isset($_GET['state'])) {
            $state = sanitize_text_field(wp_unslash($_GET['state']));
        } else {
            // Handle malformed URL with double question mark
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            if ($request_uri && preg_match('/[?&]state=([^&]+)/', $request_uri, $matches)) {
                $state = sanitize_text_field($matches[1]);
            }
        }

        if (! $this->validate_oauth_state($state)) {
            wp_safe_redirect(admin_url('admin.php?page=' . $page . '&auth_error=invalid_state'));
            exit;
        }

        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Exchange token (pass state for SaaS validation)
        $result = $this->exchange_token($oauth_token, $state);

        if ($result) {
            // Redirect to remove oauth_token and state from URL
            wp_safe_redirect(admin_url('admin.php?page=' . $page));
            exit;
        } else {
            // Redirect with error parameter
            wp_safe_redirect(admin_url('admin.php?page=' . $page . '&auth_error=1'));
            exit;
        }
    }

    /**
     * Exchange OAuth token for bearer token
     *
     * @param string $oauth_token OAuth token from callback.
     * @param string $state State parameter for CSRF validation.
     * @return bool
     */
    private function exchange_token($oauth_token, $state = '')
    {
        try {
            $webhook_secret = bin2hex(random_bytes(32));
            update_option(self::WEBHOOK_SECRET_OPTION, ( new Encryption() )->encrypt($webhook_secret));

            $body = array(
                'oauth_token'    => $oauth_token,
                'state'          => $state,
                'site_url'       => rest_url('sigmize/v1/'),
                'webhook_secret' => $webhook_secret,
            );
            $response = wp_remote_post(
                self::TOKEN_EXCHANGE_URL,
                array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body'    => wp_json_encode($body),
                    'timeout' => 30,
                )
            );

            if (is_wp_error($response)) {
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            // Check HTTP status code
            if ($response_code !== 200 && $response_code !== 201) {
                return false;
            }

            // Check if the response is successful and has the expected structure
            if (! empty($result['success']) && $result['success'] === true && ! empty($result['data'])) {
                $data = $result['data'];
                // Store the access token as bearer token. Persisting the token
                // is required for the connection to authenticate, so a failure
                // here is fatal — we must not report a successful connect
                // without a usable token.
                if (! empty($data['access_token'])) {
                    if (! $this->store_bearer_token($data['access_token'])) {
                        return false;
                    }

                    // Also store the connection ID if needed
                    if (! empty($data['connection_id'])) {
                        update_option('sigmize_connection_id', sanitize_text_field($data['connection_id']));
                    }

                    if (! empty($data['workspace_uuid'])) {
                        update_option('sigmize_workspace_uuid', sanitize_text_field($data['workspace_uuid']));
                    }

                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check authentication on admin pages
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function check_authentication()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading page parameter for navigation, nonce not applicable

        // Only check on our plugin pages
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (! $page || strpos($page, 'sigmize') !== 0) {
            return;
        }

        // Skip if already authenticated
        if ($this->is_authenticated()) {
            return;
        }

        // Skip if we're handling OAuth callback
        $oauth_token = isset($_GET['oauth_token']) ? sanitize_text_field(wp_unslash($_GET['oauth_token'])) : '';
        if ($oauth_token) {
            return;
        }

        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Show authentication page
        add_action('admin_menu', array($this, 'override_menu_pages'), 999);
    }

    /**
     * Override menu pages to show auth screen
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function override_menu_pages()
    {
        global $submenu;

        // Remove all submenu items for our plugin
        if (isset($submenu['sigmize-dashboard'])) {
            $submenu['sigmize-dashboard'] = array();
        }
    }

    /**
     * Show authentication error
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function show_auth_error()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading error parameter for display only
        $error_code = isset($_GET['auth_error']) ? sanitize_text_field(wp_unslash($_GET['auth_error'])) : '1';

        $error_messages = array(
            'invalid_state'      => __('Invalid authentication request. Please try connecting again.', 'sigmize'),
            '1'                  => __('Authentication failed. Please try again.', 'sigmize'),
        );

        $message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : $error_messages['1'];
?>
        <div class="notice notice-error">
            <p><?php echo esc_html($message); ?></p>
        </div>
<?php
    }

    /**
     * Generate OAuth state token
     *
     * @since 0.0.8
     *
     * @return string|false State token or false on failure
     */
    private function generate_oauth_state()
    {
        try {
            // Generate cryptographically secure random bytes
            $random_bytes = random_bytes(32);
            // Use URL-safe base64 encoding (no padding) to avoid issues with = in URLs
            $state = rtrim(strtr(base64_encode($random_bytes), '+/', '-_'), '=');

            // Store in transient keyed by user ID (10 minute expiration)
            $user_id = get_current_user_id();
            if (!$user_id) {
                return false;
            }

            $transient_key = 'sigmize_oauth_state_' . $user_id;
            set_transient($transient_key, $state, 600);

            return $state;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate OAuth state token
     *
     * @since 0.0.8
     *
     * @param string $state State token to validate.
     * @return bool True if valid, false otherwise
     */
    private function validate_oauth_state($state)
    {
        if (empty($state)) {
            return false;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $transient_key = 'sigmize_oauth_state_' . $user_id;
        $stored_state = get_transient($transient_key);

        // Delete transient immediately (single-use)
        delete_transient($transient_key);

        // Validate state matches
        if (empty($stored_state)) {
            return false;
        }

        // Use hash_equals for timing-safe comparison
        return hash_equals($stored_state, $state);
    }

}
