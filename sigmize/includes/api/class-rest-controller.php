<?php

/**
 * REST Controller class
 *
 * Handles WordPress-specific REST API endpoints for the Sigmize plugin.
 * Manages authentication bridging between WordPress and SaaS platform,
 * and handles experiment synchronization.
 *
 * @package Sigmize
 */

namespace Sigmize\API;

use Sigmize\Auth_Manager;
use Sigmize\Encryption;
use Sigmize\SaaS_Client;
use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * REST Controller class
 *
 * Provides REST API endpoints for:
 * - Authentication bridging (WordPress ↔ SaaS)
 * - Sync operations (SaaS webhook handling)
 * - Experiment cache management
 */
class Rest_Controller extends WP_REST_Controller
{

    /**
     * Namespace
     *
     * @var string
     */
    protected $namespace = 'sigmize/v1';

    /**
     * Transient holding the cached account overview.
     *
     * @since 1.1.2
     * @var string
     */
    const OVERVIEW_TRANSIENT = 'sigmize_account_overview';

    /**
     * How long the cached account overview stays fresh, in seconds.
     *
     * @since 1.1.2
     * @var int
     */
    const OVERVIEW_CACHE_TTL = 600;

    /**
     * Guards against double-counting the rate-limit within a single HTTP request.
     *
     * WordPress REST API invokes permission_callback twice per request (once for
     * the actual check, once to build the Allow header), so we only increment the
     * counter on the first invocation.
     *
     * @since 0.0.11
     * @var bool
     */
    private $rate_limit_counted = false;

    /**
     * Constructor
     *
     * @since 0.0.1
     */
    public function __construct()
    {
        // No initialization needed
    }

    /**
     * Register routes
     *
     * @since 0.0.1
     */
    public function register_routes()
    {
        // SaaS authentication bridging
        $this->register_auth_routes();

        // SaaS sync operations
        $this->register_sync_routes();

        // Settings routes
        $this->register_settings_routes();

        // GDPR consent routes
        $this->register_gdpr_routes();

        // Connected account overview (dashboard widget)
        $this->register_overview_routes();
    }


    /**
     * Register authentication routes (WordPress ↔ SaaS bridging)
     */
    private function register_auth_routes()
    {
        register_rest_route(
            $this->namespace,
            '/auth/disconnect',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'disconnect_from_saas'),
                    'permission_callback' => array($this, 'admin_permissions_check'),
                ),
            )
        );

        // One-click setup. Admin-only: this creates a real account on the
        // platform, so it must not be reachable by anyone but a logged-in
        // administrator acting through the dashboard.
        register_rest_route(
            $this->namespace,
            '/auth/provision',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'provision_saas_account'),
                    'permission_callback' => array($this, 'admin_permissions_check'),
                ),
            )
        );

        // Reverse-callback target. The platform calls this server-to-server
        // during provisioning to prove this site controls the URL it claimed.
        // Public on purpose — it runs before any credential exists, and
        // echoing the token back is the entire contract.
        register_rest_route(
            $this->namespace,
            '/verify',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'verify_site_callback'),
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'token' => array(
                            'required'          => true,
                            'type'              => 'string',
                            'validate_callback' => static function ($value) {
                                return is_string($value) && preg_match('/^[A-Fa-f0-9]{1,128}$/', $value) === 1;
                            },
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Register sync routes (SaaS webhook and sync management)
     */
    private function register_sync_routes()
    {
        register_rest_route(
            $this->namespace,
            '/webhook/sync',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'request_sync_from_saas'),
                    'permission_callback' => array($this, 'verify_webhook_signature'),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/experiments/cache-stats',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_cache_stats'),
                    'permission_callback' => array($this, 'admin_permissions_check'),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/experiments/request-sync',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'request_sync_from_saas'),
                    'permission_callback' => array($this, 'admin_permissions_check'),
                ),
            )
        );
    }

    /**
     * Register GDPR consent routes
     *
     * @since 0.0.11
     */
    private function register_gdpr_routes()
    {
        register_rest_route(
            $this->namespace,
            '/gdpr/consent',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'record_gdpr_consent'),
                    'permission_callback' => '__return_true',
                ),
            )
        );
    }

    /**
     * Register settings routes
     */
    private function register_settings_routes()
    {
        register_rest_route(
            $this->namespace,
            '/settings/manual-sdk',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_manual_sdk_setting'),
                    'permission_callback' => array($this, 'admin_permissions_check'),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'update_manual_sdk_setting'),
                    'permission_callback' => array($this, 'admin_permissions_check'),
                    'args'                => array(
                        'manual_sdk_loading' => array(
                            'required'          => true,
                            'type'              => 'boolean',
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ),
                    ),
                ),
            )
        );
    }





    /**
     * Register account overview route
     *
     * @since 1.1.2
     */
    private function register_overview_routes()
    {
        register_rest_route(
            $this->namespace,
            '/overview',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_account_overview'),
                    'permission_callback' => array($this, 'admin_permissions_check'),
                ),
            )
        );
    }

    /**
     * Get the connected workspace's overview from the platform.
     *
     * Cached in a transient so a wp-admin dashboard load never waits on the
     * platform more than once every OVERVIEW_CACHE_TTL seconds.
     *
     * @since 1.1.2
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|WP_Error
     */
    public function get_account_overview($request)
    {
        $workspace_uuid = sanitize_text_field((string) get_option('sigmize_workspace_uuid', ''));
        $auth_manager   = new Auth_Manager();

        if (empty($workspace_uuid) || empty($auth_manager->get_bearer_token())) {
            return new WP_Error(
                'sigmize_not_connected',
                __('This site is not connected to Sigmize.', 'sigmize'),
                array('status' => 400)
            );
        }

        $cached = get_transient(self::OVERVIEW_TRANSIENT);

        if (is_array($cached)) {
            return rest_ensure_response(
                array(
                    'success'  => true,
                    'cached'   => true,
                    'overview' => $cached,
                )
            );
        }

        $client   = new SaaS_Client($auth_manager);
        $response = $client->get(
            'workspaces/' . rawurlencode($workspace_uuid) . '/overview',
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'sigmize_overview_failed',
                __('Could not load your Sigmize overview. Please try again shortly.', 'sigmize'),
                array('status' => 502)
            );
        }

        $overview = $this->normalize_overview(
            isset($response['data']) && is_array($response['data']) ? $response['data'] : array()
        );

        set_transient(self::OVERVIEW_TRANSIENT, $overview, self::OVERVIEW_CACHE_TTL);

        return rest_ensure_response(
            array(
                'success'  => true,
                'cached'   => false,
                'overview' => $overview,
            )
        );
    }

    /**
     * Reduce the platform payload to the fields the widget renders.
     *
     * Everything the browser receives is whitelisted and cast here so a change
     * on the platform can never leak an unexpected field into wp-admin.
     *
     * @since 1.1.2
     *
     * @param array $data Decoded `data` object from the platform response.
     * @return array
     */
    private function normalize_overview($data)
    {
        $overview = array(
            'workspace'          => array(
                'name' => isset($data['workspace']['name']) ? sanitize_text_field((string) $data['workspace']['name']) : '',
            ),
            'experiments'        => $this->normalize_counts(isset($data['experiments']) ? $data['experiments'] : array()),
            'heatmaps'           => $this->normalize_counts(isset($data['heatmaps']) ? $data['heatmaps'] : array()),
            'session_recordings' => $this->normalize_counts(isset($data['session_recordings']) ? $data['session_recordings'] : array()),
            'metrics'            => array(
                'impressions'     => isset($data['metrics']['impressions']) ? (int) $data['metrics']['impressions'] : 0,
                'conversions'     => isset($data['metrics']['conversions']) ? (int) $data['metrics']['conversions'] : 0,
                'conversion_rate' => isset($data['metrics']['conversion_rate']) ? (float) $data['metrics']['conversion_rate'] : 0.0,
            ),
            'plan'               => array(
                'name'     => isset($data['plan']['name']) ? sanitize_text_field((string) $data['plan']['name']) : '',
                'features' => array(),
            ),
            'running'            => array(),
        );

        if (isset($data['plan']['features']) && is_array($data['plan']['features'])) {
            foreach (array('unique_visitors', 'experiments') as $feature) {
                if (! isset($data['plan']['features'][$feature]) || ! is_array($data['plan']['features'][$feature])) {
                    continue;
                }

                $usage = $data['plan']['features'][$feature];

                $overview['plan']['features'][$feature] = array(
                    'name'            => isset($usage['name']) ? sanitize_text_field((string) $usage['name']) : '',
                    'current'         => isset($usage['current']) ? (int) $usage['current'] : 0,
                    'limit'           => isset($usage['limit']) ? (int) $usage['limit'] : 0,
                    'percentage'      => isset($usage['percentage']) ? (float) $usage['percentage'] : 0.0,
                    'current_display' => isset($usage['current_display']) ? sanitize_text_field((string) $usage['current_display']) : '',
                    'limit_display'   => isset($usage['limit_display']) ? sanitize_text_field((string) $usage['limit_display']) : '',
                );
            }
        }

        if (isset($data['running']) && is_array($data['running'])) {
            foreach (array_slice($data['running'], 0, 5) as $experiment) {
                if (! is_array($experiment)) {
                    continue;
                }

                $overview['running'][] = array(
                    'uuid'     => isset($experiment['uuid']) ? sanitize_text_field((string) $experiment['uuid']) : '',
                    'name'     => isset($experiment['name']) ? sanitize_text_field((string) $experiment['name']) : '',
                    'category' => isset($experiment['category']) ? sanitize_key((string) $experiment['category']) : '',
                    'visitors' => isset($experiment['visitors_used']) ? (int) $experiment['visitors_used'] : 0,
                );
            }
        }

        return $overview;
    }

    /**
     * Cast a total/active count pair from the platform.
     *
     * @since 1.1.2
     *
     * @param mixed $counts Counts object from the platform response.
     * @return array
     */
    private function normalize_counts($counts)
    {
        return array(
            'total'  => is_array($counts) && isset($counts['total']) ? (int) $counts['total'] : 0,
            'active' => is_array($counts) && isset($counts['active']) ? (int) $counts['active'] : 0,
        );
    }

    // ===========================================
    // PERMISSION CALLBACKS
    // ===========================================

    /**
     * Check admin permissions
     *
     * @since 0.0.1
     */
    public function admin_permissions_check($request)
    {
        // Check user capability
        if (!current_user_can('manage_options')) {
            return false;
        }

        // Verify nonce for non-GET requests
        if ($request->get_method() !== 'GET') {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error(
                    'rest_forbidden',
                    __('Invalid nonce.', 'sigmize'),
                    array('status' => 403)
                );
            }
        }

        return true;
    }

    /**
     * Verify HMAC webhook signature for incoming SaaS webhook calls
     *
     * Applies rate limiting (max 10 requests/minute per IP) and validates
     * the X-Sigmize-Signature header using a dedicated webhook secret.
     *
     * @since 0.0.11
     */
    public function verify_webhook_signature($request)
    {
        // Rate limiting: max 10 calls per minute per IP.
        // Guard prevents double-counting when WordPress invokes this callback
        // a second time to build the Allow response header.
        $ip       = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ), FILTER_VALIDATE_IP );
        $ip       = $ip ? $ip : 'unknown';
        $rate_key = 'sigmize_webhook_rate_' . md5($ip);

        if (! $this->rate_limit_counted) {
            $call_count = (int) get_transient($rate_key);

            if ($call_count >= 10) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    __('Too many requests. Please try again later.', 'sigmize'),
                    array('status' => 429)
                );
            }

            set_transient($rate_key, $call_count + 1, 60);
            $this->rate_limit_counted = true;
        }

        // Load webhook secret.
        $secret = ( new Encryption() )->decrypt(get_option('sigmize_webhook_secret'));

        if (empty($secret)) {
            // Legacy fallback for sites connected before HMAC was introduced.
            return $this->verify_bearer_token_legacy($request);
        }

        // Validate X-Sigmize-Signature header.
        $provided_signature = $request->get_header('X-Sigmize-Signature');

        if (empty($provided_signature)) {
            return new WP_Error(
                'missing_signature',
                __('Missing X-Sigmize-Signature header.', 'sigmize'),
                array('status' => 401)
            );
        }

        $expected_signature = 'sha256=' . hash_hmac('sha256', $request->get_body(), $secret);

        if (! hash_equals($expected_signature, $provided_signature)) {
            return new WP_Error(
                'invalid_signature',
                __('Invalid webhook signature.', 'sigmize'),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Verify webhook request using legacy bearer-token authentication
     *
     * Used as a fallback for sites connected before HMAC webhook secrets were
     * introduced. Checks the Authorization header against the stored bearer token.
     *
     * @since 0.0.11
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function verify_bearer_token_legacy($request)
    {
        $stored_token = ( new Encryption() )->decrypt(get_option('sigmize_bearer_token'));

        if (empty($stored_token)) {
            return new WP_Error(
                'no_bearer_token',
                __('No authentication credentials configured.', 'sigmize'),
                array('status' => 401)
            );
        }

        $auth_header = $request->get_header('Authorization');

        if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
            return new WP_Error(
                'missing_authorization',
                __('Missing or invalid Authorization header.', 'sigmize'),
                array('status' => 401)
            );
        }

        $provided_token = substr($auth_header, strlen('Bearer '));

        if (! hash_equals($stored_token, $provided_token)) {
            return new WP_Error(
                'invalid_bearer_token',
                __('Invalid bearer token.', 'sigmize'),
                array('status' => 401)
            );
        }

        return true;
    }


    // ===========================================
    // AUTHENTICATION BRIDGING (WordPress ↔ SaaS)
    // ===========================================

    /**
     * Answer the platform's site-ownership check by echoing its token.
     *
     * Deliberately does nothing else. This runs before any credential exists,
     * so it cannot authenticate the caller; proving that whoever asked can also
     * read this site's response is the whole point.
     *
     * @since 1.1.2
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function verify_site_callback($request)
    {
        return rest_ensure_response(
            array(
                'token' => (string) $request->get_param('token'),
            )
        );
    }

    /**
     * Create a Sigmize account for this site and connect it, in one call.
     *
     * The OAuth flow assumes the user already has an account and can complete
     * a redirect. This is the path for someone who has neither: the setup
     * checklist button.
     *
     * @since 1.1.2
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|WP_Error
     */
    public function provision_saas_account($request)
    {
        // Not an error: the caller asked for this site to be set up and it
        // already is. Reporting a failure for a working connection is both
        // wrong and the state most existing sites land in, so answer the way
        // an idempotent request should and let the checklist tick the step.
        if (Auth_Manager::has_connection()) {
            return $this->connected_response(
                __('This site is already connected to Sigmize.', 'sigmize')
            );
        }

        // Which product's checklist sent this. The request comes from an
        // admin's own browser via a nonce-checked route, so this is the
        // checklist reporting which copy of itself rendered the button, not
        // an outside claim. The platform allowlists it regardless.
        $source = sanitize_key((string) $request->get_param('source'));

        $result = ( new Auth_Manager() )->provision_account($source);

        if (is_wp_error($result)) {
            // An existing account is not a failure, it is a fork in the road:
            // provisioning is refused because controlling this site proves
            // nothing about owning that account, but the person clicking is
            // most likely its owner. Hand back somewhere to go, or the message
            // is a dead end.
            if ($result->get_error_code() === 'email_exists') {
                return new WP_Error(
                    'email_exists',
                    __('You already have a Sigmize account. Connect this site to it to finish setup.', 'sigmize'),
                    array(
                        'status'      => 409,
                        // The plugin's own page, not the SaaS: the OAuth flow
                        // needs a state nonce and a callback URL minted here.
                        'connect_url' => admin_url('admin.php?page=sigmize-dashboard'),
                    )
                );
            }

            return $result;
        }

        return $this->connected_response(
            __('Your site is now connected and tracking.', 'sigmize')
        );
    }

    /**
     * The shape the checklist expects once this site has a working connection.
     *
     * @since 1.1.2
     *
     * @param string $message Human-readable outcome.
     * @return \WP_REST_Response
     */
    private function connected_response($message)
    {
        return rest_ensure_response(
            array(
                'success'        => true,
                'message'        => $message,
                'workspace_uuid' => sanitize_text_field(get_option('sigmize_workspace_uuid', '')),
                'dashboard_url'  => admin_url('admin.php?page=sigmize-dashboard'),
            )
        );
    }

    /**
     * Disconnect from SaaS platform
     *
     * @since 0.0.1
     */
    public function disconnect_from_saas($request)
    {
        // Legacy cleanup: the bearer token is no longer stored in a cookie, but
        // clear any auth_token cookie left in the browser by older versions.
        $secure_cookie_manager = \Sigmize\Secure_Cookie_Manager::get_instance();
        $secure_cookie_manager->delete_secure_cookie('auth_token');

        // Clear all SaaS-related options
        delete_option('sigmize_bearer_token');
        delete_option('sigmize_auth_token');
        delete_option('sigmize_webhook_secret');
        delete_option('sigmize_connection_id');
        delete_option('sigmize_workspace_uuid');
        delete_option('sigmize_experiments');
        delete_option('sigmize_last_sync_time');
        delete_option('sigmize_gdpr_settings');
        delete_option('sigmize_gdpr_enabled');
        delete_option('sigmize_manual_sdk_loading');
        delete_transient(self::OVERVIEW_TRANSIENT);

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Successfully disconnected from SaaS platform.', 'sigmize')
        ));
    }


    // ===========================================
    // SYNC OPERATIONS (SaaS Webhook & Sync Management)
    // ===========================================

    /**
     * Get cached experiments statistics
     *
     * @since 0.0.1
     */
    public function get_cache_stats($request)
    {
        $cache = get_option('sigmize_experiments', array());
        $experiments = isset($cache['experiments']) && is_array($cache['experiments']) ? $cache['experiments'] : array();
        $count = count($experiments);

        $last_sync = get_option('sigmize_last_sync_time');

        return rest_ensure_response(array(
            'success' => true,
            'experiments_count' => $count,
            'last_sync_time' => $last_sync,
            'formatted_time' => $last_sync ? wp_date('Y-m-d H:i:s', strtotime($last_sync)) : null,
        ));
    }

    /**
     * Request sync from SaaS platform
     *
     * @since 0.0.1
     */
    public function request_sync_from_saas($request)
    {
        try {
            $bearer_token = ( new Encryption() )->decrypt(get_option('sigmize_bearer_token'));

            if (empty($bearer_token)) {
                return new WP_Error(
                    'not_connected',
                    __('Not connected to SaaS platform.', 'sigmize'),
                    array('status' => 400)
                );
            }

            // Get workspace UUID
            $workspace_uuid = sanitize_text_field(get_option('sigmize_workspace_uuid'));

            if (empty($workspace_uuid)) {
                return new WP_Error(
                    'no_workspace_uuid',
                    __('Workspace UUID not found.', 'sigmize'),
                    array('status' => 400)
                );
            }

            // Directly fetch experiments from SaaS platform
            $response = wp_remote_get(
                SIGMIZE_SAAS_API_BASE_URL . '/api/v1/experiments?status=running&per_page=200&workspace_uuid=' . urlencode($workspace_uuid),
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $bearer_token,
                        'Content-Type' => 'application/json',
                    ),
                    'timeout' => 120,
                )
            );

            if (is_wp_error($response)) {
                return new WP_Error(
                    'sync_request_failed',
                    __('Failed to fetch experiments from SaaS platform.', 'sigmize'),
                    array('status' => 500)
                );
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                return new WP_Error(
                    'sync_request_failed',
                    sprintf(
                        /* translators: %d: HTTP error code returned from SaaS platform */
                        __('SaaS platform returned error code: %d', 'sigmize'),
                        $response_code
                    ),
                    array('status' => 500)
                );
            }

            $body = wp_remote_retrieve_body($response);
            $experiments_data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error(
                    'invalid_response',
                    __('Invalid JSON response from SaaS platform.', 'sigmize'),
                    array('status' => 500)
                );
            }

            // Extract experiments from the response
            $experiments_raw = array();
            if (isset($experiments_data['data']) && is_array($experiments_data['data'])) {
                $experiments_raw = $experiments_data['data'];
            } elseif (is_array($experiments_data)) {
                $experiments_raw = $experiments_data;
            }

            // Transform experiments to the expected format for maybe_redirect
            $experiments_formatted = array();
            $experiment_count = 0;

            foreach ($experiments_raw as $experiment) {
                // Convert to object if it's an array
                if (is_array($experiment)) {
                    $experiment = (object) $experiment;
                }

                // Skip if missing required fields
                if (empty($experiment->uuid) || empty($experiment->variants)) {
                    continue;
                }

                // Only store running experiments to optimize WordPress performance (handle both formats)
                $status_value = is_array($experiment->status) ?
                    $experiment->status['value'] :
                    $experiment->status->value;
                if (empty($status_value) || $status_value !== 'running') {
                    continue;
                }

                // Convert variants to objects
                $variants = array();
                foreach ($experiment->variants as $variant) {
                    if (is_array($variant)) {
                        $variant = (object) $variant;
                    }
                    $variants[] = $variant;
                }

                // Format experiment data as expected by maybe_redirect
                $experiment_data = array(
                    'experiment' => $experiment,
                    'variants' => $variants
                );

                // Store with UUID as key
                $experiments_formatted[$experiment->uuid] = $experiment_data;
                $experiment_count++;
            }

            // Store in the expected format with metadata
            $cache_data = array(
                'experiments' => $experiments_formatted,
                'last_sync' => time(),
                'sync_time' => current_time('mysql')
            );

            update_option('sigmize_experiments', $cache_data);
            update_option('sigmize_last_sync_time', current_time('mysql'));

            // A sync means the platform-side numbers just moved; let the
            // dashboard widget pull fresh figures instead of serving stale ones.
            delete_transient(self::OVERVIEW_TRANSIENT);

            // Fetch workspace settings from SaaS platform
            $settings_response = wp_remote_get(
                SIGMIZE_SAAS_API_BASE_URL . '/api/v1/workspace/settings',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $bearer_token,
                        'Content-Type' => 'application/json',
                    ),
                    'timeout' => 30,
                )
            );

            $settings_synced = false;
            if (! is_wp_error($settings_response) && wp_remote_retrieve_response_code($settings_response) === 200) {
                $settings_body = wp_remote_retrieve_body($settings_response);
                $settings_data = json_decode($settings_body, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($settings_data['data']['settings'])) {
                    // Save the settings to sigmize_settings option
                    update_option('sigmize_settings', $settings_data['data']['settings']);
                    $settings_synced = true;
                }
            }

            // Fetch GDPR settings from SaaS platform
            $gdpr_response = wp_remote_get(
                SIGMIZE_SAAS_API_BASE_URL . '/api/v1/workspaces/' . urlencode($workspace_uuid) . '/gdpr-settings',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $bearer_token,
                        'Content-Type' => 'application/json',
                    ),
                    'timeout' => 30,
                )
            );

            $gdpr_synced = false;
            if (! is_wp_error($gdpr_response) && wp_remote_retrieve_response_code($gdpr_response) === 200) {
                $gdpr_body = wp_remote_retrieve_body($gdpr_response);
                $gdpr_data = json_decode($gdpr_body, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($gdpr_data['data'])) {
                    // Save the GDPR settings to sigmize_gdpr_settings option
                    update_option('sigmize_gdpr_settings', $gdpr_data['data']);

                    // Also store gdpr_enabled flag separately for quick access
                    $gdpr_enabled = isset($gdpr_data['data']['gdpr_enabled']) ? (bool) $gdpr_data['data']['gdpr_enabled'] : false;
                    update_option('sigmize_gdpr_enabled', $gdpr_enabled);

                    $gdpr_synced = true;
                }
            }

            // Update connection status with latest sync info
            $workspace_uuid = sanitize_text_field(get_option('sigmize_workspace_uuid'));
            if (!empty($workspace_uuid)) {
                $saas_client = new \Sigmize\SaaS_Client();
                $saas_client->update_connection_status(
                    $workspace_uuid,
                    SIGMIZE_VERSION,
                    $experiment_count
                );
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => sprintf(
                    /* translators: %d: number of experiments synced */
                    _n(
                        'Successfully synced %d experiment from SaaS platform.',
                        'Successfully synced %d experiments from SaaS platform.',
                        $experiment_count,
                        'sigmize'
                    ),
                    $experiment_count
                ),
                'synced_count' => $experiment_count,
                'formatted_experiments' => count($experiments_formatted),
                'settings_synced' => $settings_synced,
                'gdpr_synced' => $gdpr_synced
            ));
        } catch (\Exception $e) {
            return new WP_Error(
                'sync_exception',
                sprintf(
                    /* translators: %s: exception error message */
                    __('An error occurred during sync: %s', 'sigmize'),
                    $e->getMessage()
                ),
                array('status' => 500)
            );
        }
    }


    // ===========================================
    // GDPR CONSENT
    // ===========================================

    /**
     * Record GDPR consent and set a server-signed consent cookie
     *
     * Public endpoint — no authentication required. The HMAC signature
     * on the cookie is the security measure against forgery.
     *
     * @since 0.0.11
     */
    public function record_gdpr_consent($request)
    {
        // Rate limit: max 20 requests per minute per IP.
        $ip       = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ), FILTER_VALIDATE_IP );
        $ip       = $ip ? $ip : 'unknown';
        $rate_key = 'sigmize_gdpr_rate_' . md5($ip);
        $count    = (int) get_transient($rate_key);
        if ($count >= 20) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Too many requests. Please try again later.', 'sigmize'),
                array('status' => 429)
            );
        }
        set_transient($rate_key, $count + 1, 60);

        $cookie_manager = \Sigmize\Secure_Cookie_Manager::get_instance();
        $cookie_value   = $cookie_manager->set_gdpr_consent_cookie();

        if (false === $cookie_value) {
            return new WP_Error(
                'consent_cookie_failed',
                __('Failed to set consent cookie.', 'sigmize'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'consent' => 'accepted',
        ));
    }


    // ===========================================
    // SETTINGS MANAGEMENT
    // ===========================================

    /**
     * Get manual SDK loading setting
     *
     * @since 0.0.5
     */
    public function get_manual_sdk_setting($request)
    {
        $manual_sdk_loading = get_option('sigmize_manual_sdk_loading', false);

        return rest_ensure_response(array(
            'success' => true,
            'manual_sdk_loading' => (bool) $manual_sdk_loading
        ));
    }

    /**
     * Update manual SDK loading setting
     *
     * @since 0.0.5
     */
    public function update_manual_sdk_setting($request)
    {
        $manual_sdk_loading = $request->get_param('manual_sdk_loading');

        update_option('sigmize_manual_sdk_loading', (bool) $manual_sdk_loading);

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('SDK loading setting updated successfully.', 'sigmize'),
            'manual_sdk_loading' => (bool) $manual_sdk_loading
        ));
    }
}
