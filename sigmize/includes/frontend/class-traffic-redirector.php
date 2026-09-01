<?php

/**
 * Traffic Redirector
 *
 * Handles experiment variant selection and redirection for A/B testing.
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend;

use Sigmize\Encryption;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Traffic Redirector class
 *
 * Manages visitor assignment to experiment variants and handles
 * redirection to variant URLs for A/B tests.
 */
class Traffic_Redirector implements Interface_Module
{

    /**
     * URL Matcher
     *
     * @var Interface_URL_Matcher
     */
    protected $url_matcher;

    /**
     * Experiment Utility
     *
     * @var Experiment_Utility
     */
    protected $experiment_utility;

    /**
     * Cache for active experiments with variants
     *
     * @var array|null
     */
    protected $experiments_cache = null;

    /**
     * Constructor
     *
     * @since 0.0.1
     *
     * @param Interface_URL_Matcher $url_matcher URL Matcher.
     * @param Experiment_Utility|null $experiment_utility Experiment Utility.
     */
    public function __construct(
        Interface_URL_Matcher $url_matcher,
        ?Experiment_Utility $experiment_utility = null
    ) {
        $this->url_matcher = $url_matcher;

        // Create Experiment_Utility if not provided
        if (null === $experiment_utility) {
            $this->experiment_utility = new Experiment_Utility($url_matcher);
        } else {
            $this->experiment_utility = $experiment_utility;
        }
    }

    /**
     * Initialize the module
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function init()
    {
        $this->register_hooks();
    }

    /**
     * Register hooks
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function register_hooks()
    {
        // Handle sigmize_aid and sigmize_eid parameters very early
        add_action('template_redirect', array($this, 'handle_sigmize_aid_parameter'), 1);

        // Run on 'wp' hook (before template_redirect) to prevent visible redirects
        // This ensures redirects happen before any content is output
        add_action('wp', array($this, 'maybe_redirect'), 1);
    }

    /**
     * Check if we should skip redirection
     *
     * @since 0.0.1
     *
     * @return bool True if redirection should be skipped
     */
    protected function should_skip_redirection()
    {
        // Skip if in admin area
        if (is_admin()) {
            return true;
        }

        // Skip if requesting static assets
        if ($this->is_static_asset_request()) {
            return true;
        }

        // Skip if REST API request
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        // Skip if AJAX request
        if (wp_doing_ajax()) {
            return true;
        }

        // Skip if CRON
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        // Skip if CLI
        if (defined('WP_CLI')) {
            return true;
        }

        return false;
    }

    /**
     * Check if GDPR consent is required and not yet given
     *
     * @since 0.0.11
     *
     * @return bool True if redirection should be skipped due to missing GDPR consent
     */
    protected function should_skip_for_gdpr()
    {
        $gdpr_enabled = get_option('sigmize_gdpr_enabled', false);
        if ($gdpr_enabled) {
            // Verify the HMAC-signed consent cookie — plain 'accepted' values are rejected
            $cookie_manager = \Sigmize\Secure_Cookie_Manager::get_instance();
            if (! $cookie_manager->verify_gdpr_consent_cookie()) {
                return true; // Skip redirection - no valid consent
            }
            // User has consented, allow redirection to continue
        }

        return false;
    }

    /**
     * Check if the current request is for a static asset
     *
     * @since 0.0.1
     *
     * @return bool True if requesting a static asset
     */
    protected function is_static_asset_request()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        if (!$path) {
            return false;
        }

        // Static extensions
        static $ext_pattern = '/\.(js|js\.map|css|css\.map|jpe?g|png|gif|webp|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|pdf|zip|xml|json)$/i';

        // Strip query/fragment & trailing slash
        $clean_path = rtrim(preg_replace('/[?#].*/', '', $path), '/');

        return (bool) preg_match($ext_pattern, $clean_path);
    }

    /**
     * Maybe redirect the visitor based on experiment configuration
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function maybe_redirect()
    {

        // Skip redirection if needed
        if ($this->should_skip_redirection()) {
            return;
        }

        // Get current URL
        $current_url = $this->url_matcher->get_current_url();


        // Safety check: prevent infinite redirects by checking if we've already redirected
        static $redirect_count = 0;
        if ($redirect_count >= 1) {
            return;
        }

        // Ensure current URL has a protocol for proper comparison
        if (! empty($current_url) && ! preg_match('/^https?:\/\//', $current_url)) {
            // Add protocol if missing
            $protocol = is_ssl() ? 'https://' : 'http://';
            $current_url = $protocol . $current_url;
        }

        // Check if experiments are disabled via sigmize_disabled parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- URL parameter for test/debug mode, not a form submission
        if (isset($_GET['sigmize_disabled'])) {
            // Skip all experiments - similar to JS SDK behavior
            return;
        }

        // Check if this is a WordPress search request
        // Skip experiments on search results to ensure search functionality works correctly
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- URL parameter for search query, not a form submission
        if (isset($_GET['s'])) {
            // Skip all experiments on search results pages
            return;
        }

        // Check for test mode URL parameters (preview mode bypasses GDPR gate).
        $test_variant = $this->get_test_variant_from_url();
        if ($test_variant) {
            $this->handle_test_mode_redirect($test_variant);
            return;
        }

        // GDPR consent check: skip normal experiment redirects when consent is missing.
        // Preview mode (handled above) is exempt so authorised editors can always preview.
        if ($this->should_skip_for_gdpr()) {
            return;
        }

        // Step 1: Get all active experiments with variants
        $experiments_with_variants = $this->get_active_experiments_with_variants();

        if (empty($experiments_with_variants)) {
            return;
        }

        // Step 2: Find best matching experiment using priority-based selection (like JS SDK)
        $selected_experiment_data = $this->select_best_experiment($experiments_with_variants, $current_url);


        if (! $selected_experiment_data) {
            return;
        }

        $matching_experiment = $selected_experiment_data['experiment'];
        $matching_variants = $selected_experiment_data['variants'];

        // Apply filter for matching experiment
        $matching_experiment = apply_filters('sigmize_matching_experiment', $matching_experiment, ! empty($matching_experiment), $current_url);

        if (! $matching_experiment || empty($matching_variants)) {
            return;
        }

        // Check if matching experiment is running (handle both array and object format)
        $status_value = is_array($matching_experiment->status) ?
            $matching_experiment->status['value'] :
            $matching_experiment->status->value;
        if (empty($status_value) || $status_value !== 'running') {
            return;
        }

        // Detect if user was redirected by a previous request. Run this only once a
        // running experiment matches the current URL — pages without a matching
        // experiment never redirect, so writing the loop-tracker cookie there would
        // only make every response uncacheable (Set-Cookie forces a cache bypass).
        if ($this->detect_redirect_loop($current_url)) {
            return;
        }

        // Step 3: Check for existing assignment
        $existing_variant = $this->get_existing_assignment($matching_experiment);

        if ($existing_variant) {
            // User already has assignment, check if redirect needed
            $variant_url = $this->get_variant_url($existing_variant, $matching_experiment);

            if ($variant_url && ! $this->urls_are_equivalent($current_url, $variant_url)) {

                $redirect_count++;
                $redirect_url = $this->preserve_query_params($current_url, $variant_url);
                wp_safe_redirect($redirect_url, 302);
                exit;
            }

            return;
        }

        // Step 4: For new users, ALWAYS do random assignment (proper A/B testing)
        $assigned_variant = $this->get_weighted_random_variant($matching_variants);
        if (! $assigned_variant) {
            return;
        }

        // Step 5: Create temporary assignment cookie to persist across redirects
        // This prevents variant switching during redirect chain
        $this->create_temporary_assignment_cookie($assigned_variant, $matching_experiment);

        // Step 6: Handle redirects only if user is NOT on the assigned variant page
        $variant_url = $this->get_variant_url($assigned_variant, $matching_experiment);
        if ($variant_url && ! $this->urls_are_equivalent($current_url, $variant_url)) {
            // Store variant data in session for injection after redirect
            $this->inject_selected_variant_data_cookie($assigned_variant, $matching_experiment);

            $redirect_count++;
            $redirect_url = $this->preserve_query_params($current_url, $variant_url);

            wp_safe_redirect($redirect_url, 302);

            exit; // Exit immediately after redirect to prevent further execution
        }

        // If already on correct URL, inject data for JavaScript to record assignment
        $this->inject_selected_variant_data_cookie($assigned_variant, $matching_experiment);
    }

    /**
     * Detect potential redirect loops using referrer and redirect tracking
     *
     * @since 0.0.1
     *
     * @param string $current_url Current URL being accessed
     * @return bool True if redirect loop detected
     */
    protected function detect_redirect_loop($current_url)
    {
        // Check if user has redirect tracking cookie
        if (! isset($_COOKIE['sigmize_redirect_tracker'])) {
            // Set tracking cookie for this URL with timestamp
            $tracking_data = array(
                'url' => $current_url,
                'timestamp' => time(),
                'count' => 1
            );
            setcookie('sigmize_redirect_tracker', wp_json_encode($tracking_data), array(
                'expires' => time() + 300,
                'path' => '/',
                'domain' => $this->get_cookie_domain(),
                'secure' => is_ssl(),
                'httponly' => true, // Server-only cookie for security
                'samesite' => 'Lax'
            ));
            return false; // First visit, no loop detected
        }

        // Parse existing tracking data
        $raw_cookie = isset($_COOKIE['sigmize_redirect_tracker']) ? wp_unslash($_COOKIE['sigmize_redirect_tracker']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on next line with whitelist regex
        $sanitized_cookie = preg_replace('/[^a-zA-Z0-9\{\}\[\]:,"_\-\.\s]/', '', $raw_cookie);
        $tracking_data = json_decode($sanitized_cookie, true);
        if (! is_array($tracking_data)) {
            // Invalid data, reset
            setcookie('sigmize_redirect_tracker', '', array(
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => $this->get_cookie_domain(),
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax'
            ));
            return false;
        }

        // Check if same URL accessed recently (within 10 seconds)
        if (
            isset($tracking_data['url'], $tracking_data['timestamp']) &&
            $this->urls_are_equivalent($tracking_data['url'], $current_url) &&
            (time() - $tracking_data['timestamp']) < 10
        ) {

            // Increment count
            $tracking_data['count'] = ($tracking_data['count'] ?? 1) + 1;

            // If accessed same URL multiple times quickly, it's likely a redirect loop
            if ($tracking_data['count'] > 2) {
                // Clear tracking cookie
                setcookie('sigmize_redirect_tracker', '', array(
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => $this->get_cookie_domain(),
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ));
                return true;
            }
        }

        // Update tracking data
        $tracking_data = array(
            'url' => $current_url,
            'timestamp' => time(),
            'count' => (isset($tracking_data['count']) &&
                $this->urls_are_equivalent($tracking_data['url'] ?? '', $current_url)) ?
                $tracking_data['count'] + 1 : 1
        );
        setcookie('sigmize_redirect_tracker', wp_json_encode($tracking_data), array(
            'expires' => time() + 300,
            'path' => '/',
            'domain' => $this->get_cookie_domain(),
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax'
        ));

        return false;
    }

    /**
     * Check if URLs are equivalent (exact match only)
     *
     * @since 0.0.1
     *
     * @param string $current_url Current URL to check
     * @param string $variant_url Variant URL to match against
     * @return bool True if URLs match exactly
     */
    protected function urls_are_equivalent($current_url, $variant_url)
    {
        if (empty($current_url) || empty($variant_url)) {
            return false;
        }

        // Use clean normalization for exact matching
        $url_matcher = new URL_Matcher_Impl();
        return $url_matcher->url_matches_pattern($current_url, $variant_url);
    }


    /**
     * Check for existing assignment from SDK cookies (single source of truth)
     *
     * @since 0.0.1
     *
     * @param object $experiment Experiment object
     * @return object|null Variant object if assignment exists, null otherwise
     */
    protected function get_existing_assignment($experiment)
    {
        // PRIORITY 1: Check temporary PHP assignment cookie (for redirect persistence)
        $temp_cookie_name = 'sigmize_temp_assignment_' . $experiment->uuid;
        if (isset($_COOKIE[$temp_cookie_name])) {
            $raw_cookie = wp_unslash($_COOKIE[$temp_cookie_name]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with URL decode and JSON validation

            // URL decode and strip slashes
            $decoded_cookie = rawurldecode($raw_cookie);
            $clean_cookie = stripslashes($decoded_cookie);

            // Parse temporary cookie: {"v": "variant_uuid", "e": timestamp}
            $temp_data = json_decode($clean_cookie, true);

            if (is_array($temp_data) && isset($temp_data['v'], $temp_data['e'])) {
                // Check if not expired
                if ($temp_data['e'] > time()) {
                    $variant_uuid = $temp_data['v'];

                    // Find variant object by UUID
                    if (isset($experiment->variants) && is_array($experiment->variants)) {
                        foreach ($experiment->variants as $variant) {
                            if ($variant->uuid === $variant_uuid) {
                                return $variant;
                            }
                        }
                    }
                }
            }
        }

        // PRIORITY 2: Check SDK cookie format: sigmize_assignment_{experiment_uuid}
        $cookie_name = 'sigmize_assignment_' . $experiment->uuid;

        if (isset($_COOKIE[$cookie_name])) {
            $raw_cookie = wp_unslash($_COOKIE[$cookie_name]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with URL decode and JSON validation

            // URL decode and strip slashes (handle WordPress/PHP escaping)
            $decoded_cookie = rawurldecode($raw_cookie);
            $clean_cookie = stripslashes($decoded_cookie);

            // Parse SDK cookie JSON format: {"a": "assignment_id", "v": "variant_uuid", "e": timestamp}
            $assignment_data = json_decode($clean_cookie, true);

            if (is_array($assignment_data) && isset($assignment_data['v'], $assignment_data['e'])) {
                // Check if assignment is not expired
                if ($assignment_data['e'] > time()) {
                    $variant_uuid = $assignment_data['v'];

                    // Find variant object by UUID
                    if (isset($experiment->variants) && is_array($experiment->variants)) {
                        foreach ($experiment->variants as $variant) {
                            if ($variant->uuid === $variant_uuid) {
                                return $variant;
                            }
                        }
                    }
                }
            }
        }

        // PRIORITY 3: Check sigmize_selected_variant cookie as fallback
        if (isset($_COOKIE['sigmize_selected_variant'])) {
            $raw_cookie = wp_unslash($_COOKIE['sigmize_selected_variant']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with URL decode and JSON validation

            // URL decode and strip slashes
            $decoded_cookie = rawurldecode($raw_cookie);
            $clean_cookie = stripslashes($decoded_cookie);

            // Parse cookie: {"experiment_uuid": "...", "variant_uuid": "..."}
            $selected_data = json_decode($clean_cookie, true);

            if (
                is_array($selected_data) &&
                isset($selected_data['experiment_uuid'], $selected_data['variant_uuid']) &&
                $selected_data['experiment_uuid'] === $experiment->uuid
            ) {

                $variant_uuid = $selected_data['variant_uuid'];

                // Find variant object by UUID
                if (isset($experiment->variants) && is_array($experiment->variants)) {
                    foreach ($experiment->variants as $variant) {
                        if ($variant->uuid === $variant_uuid) {
                            return $variant;
                        }
                    }
                }
            }
        }

        return null;
    }


    /**
     * Create temporary assignment cookie to persist variant selection across redirects
     *
     * @since 0.0.1
     *
     * @param object $variant Selected variant
     * @param object $experiment Experiment object
     * @return void
     */
    protected function create_temporary_assignment_cookie($variant, $experiment)
    {
        $temp_cookie_name = 'sigmize_temp_assignment_' . $experiment->uuid;

        // Create temporary cookie with short expiry (just long enough for redirect chain)
        $temp_data = array(
            'v' => $variant->uuid,  // variant UUID
            'e' => time() + 300     // expires in 5 minutes
        );

        $cookie_value = wp_json_encode($temp_data);

        setcookie($temp_cookie_name, $cookie_value, array(
            'expires' => time() + 300,  // 5 minutes - long enough for redirect chain
            'path' => '/',
            'domain' => $this->get_cookie_domain(),
            'secure' => is_ssl(),
            'httponly' => true,  // Server-only cookie - JS SDK doesn't need to read this
            'samesite' => 'Lax'
        ));

        // CRITICAL: Also set in $_COOKIE for immediate availability in the SAME request
        $_COOKIE[$temp_cookie_name] = $cookie_value;
    }


    /**
     * Inject selected variant data for JavaScript recording
     *
     * @since 0.0.1
     *
     * @param object $variant Selected variant
     * @param object $experiment Experiment object
     * @return void
     */
    protected function inject_selected_variant_data_cookie($variant, $experiment)
    {
        setcookie('sigmize_selected_variant', wp_json_encode([
            "experiment_uuid" => $experiment->uuid,
            "variant_uuid" => $variant->uuid
        ]), array(
            'expires' => time() + 60,
            'path' => '/',
            'domain' => $this->get_cookie_domain(),
            'secure' => is_ssl(),
            'httponly' => false, // Must be false - frontend JS SDK needs to read this cookie
            'samesite' => 'Lax'
        ));
    }


    /**
     * Select the best experiment using per-page mutual exclusivity (replicates JS SDK determineWinner)
     *
     * @since 0.0.1
     *
     * @param array  $experiments_with_variants All available experiments with variants
     * @param string $current_url Current URL to match against
     * @return array|null Selected experiment data or null if none match
     */
    protected function select_best_experiment($experiments_with_variants, $current_url)
    {
        if (empty($experiments_with_variants)) {
            return null;
        }


        // STEP 1: Find all experiments that match current URL
        $eligible_experiments = array();

        foreach ($experiments_with_variants as $experiment_data) {
            $experiment = $experiment_data['experiment'];
            $variants = $experiment_data['variants'];

            // Skip non-running experiments (handle both array and object format)
            $status_value = is_array($experiment->status) ?
                $experiment->status['value'] :
                $experiment->status->value;
            if (empty($status_value) || $status_value !== 'running') {
                continue;
            }

            // Check if current URL matches any entry point
            $entry_points = $this->get_experiment_entry_points($experiment, $variants);

            if (empty($entry_points)) {
                continue;
            }

            $matches_url = false;
            foreach ($entry_points as $entry_point) {
                if ($this->url_matcher->url_matches_pattern($current_url, $entry_point)) {
                    $matches_url = true;
                    break;
                }
            }

            if (! $matches_url) {
                continue;
            }

            // Check for existing assignment to THIS experiment
            $existing_assignment = $this->get_existing_assignment($experiment);

            $eligible_experiments[] = array(
                'experiment' => $experiment,
                'variants' => $variants,
                'entry_points' => $entry_points,
                'has_existing_assignment' => ! empty($existing_assignment),
                'priority' => $this->get_experiment_priority($experiment),
                'experiment_data' => $experiment_data
            );
        }

        if (empty($eligible_experiments)) {
            return null;
        }

        // STEP 2: JS SDK determineWinner logic - Prioritize experiments with existing assignments
        $experiments_with_assignments = array_filter($eligible_experiments, function ($exp) {
            return $exp['has_existing_assignment'];
        });

        if (! empty($experiments_with_assignments)) {
            // Multiple experiments with assignments - pick highest priority (like JS SDK)
            usort($experiments_with_assignments, function ($a, $b) {
                return $a['priority'] - $b['priority']; // Lower number = higher priority
            });

            $selected = $experiments_with_assignments[0];
            return $selected['experiment_data'];
        }

        // STEP 3: No existing assignments - select highest priority experiment (per-page mutual exclusivity)
        usort($eligible_experiments, function ($a, $b) {
            return $a['priority'] - $b['priority']; // Lower number = higher priority
        });

        $selected = $eligible_experiments[0];

        return $selected['experiment_data'];
    }


    /**
     * Get experiment priority as numeric value (lower = higher priority)
     *
     * @since 0.0.1
     *
     * @param object $experiment Experiment object
     * @return int Priority as integer (1 = highest, 999 = lowest)
     */
    protected function get_experiment_priority($experiment)
    {
        // Simplified priority handling - handle both array and object format
        if (is_array($experiment->priority)) {
            return (int) ($experiment->priority['order'] ?? 999);
        } else {
            return (int) ($experiment->priority->order ?? 999);
        }
    }

    /**
     * Get all active experiments with their variants from SaaS cache
     *
     * @since 0.0.1
     *
     * @return array Array of experiment data with variants
     */
    protected function get_active_experiments_with_variants()
    {
        // Return cached data if available
        if (null !== $this->experiments_cache) {
            return $this->experiments_cache;
        }

        // Get data from options table (synced from SaaS)
        $cached_data = get_option('sigmize_experiments', array());

        // Ensure cached_data is an array
        if (!is_array($cached_data)) {
            $cached_data = array();
        }

        if (empty($cached_data['experiments'])) {
            $this->experiments_cache = array();
            return array();
        }

        // Convert cached experiments to expected format with experiment + variants structure
        $experiments_array = array();

        if (is_array($cached_data['experiments'])) {
            foreach ($cached_data['experiments'] as $uuid => $experiment_response) {

                // Handle API response format
                $experiment = null;
                if (isset($experiment_response['success']) && $experiment_response['success'] && isset($experiment_response['data'])) {
                    $experiment = (object) $experiment_response['data'];
                } elseif (isset($experiment_response['experiment'])) {
                    $experiment = is_object($experiment_response['experiment']) ? $experiment_response['experiment'] : (object) $experiment_response['experiment'];
                } else {
                    $experiment = is_object($experiment_response) ? $experiment_response : (object) $experiment_response;
                }

                if (! $experiment) {
                    continue;
                }

                // Convert arrays to objects recursively for compatibility
                $experiment = json_decode(json_encode($experiment));

                // Skip if not running (after json conversion, status becomes an object)
                if (empty($experiment->status->value) || $experiment->status->value !== 'running') {
                    continue;
                }

                // Extract variants directly from experiment data
                $variants = array();
                if (isset($experiment->variants) && is_array($experiment->variants)) {
                    foreach ($experiment->variants as $variant_data) {
                        $variant = is_object($variant_data) ? $variant_data : (object) $variant_data;
                        $variants[] = $variant;
                    }
                }

                if (! empty($variants)) {
                    $experiments_array[] = array(
                        'experiment' => $experiment,
                        'variants' => $variants
                    );
                }
            }
        }

        // Sort experiments by priority (lower number = higher priority) for better performance
        usort($experiments_array, function ($a, $b) {
            $priority_a = $this->get_experiment_priority($a['experiment']);
            $priority_b = $this->get_experiment_priority($b['experiment']);
            return $priority_a - $priority_b;
        });

        // Cache the results
        $this->experiments_cache = $experiments_array;

        return $this->experiments_cache;
    }

    /**
     * Get experiment entry points
     *
     * @since 0.0.1
     *
     * @param object $experiment Experiment object.
     * @param array  $variants   Pre-fetched variants.
     * @return array Array of entry point URLs
     */
    protected function get_experiment_entry_points($experiment, $variants = null)
    {
        if (null !== $variants) {
            $entry_points = array();

            foreach ($variants as $variant) {
                $variant_url = $this->get_variant_url($variant, $experiment);
                if ($variant_url) {
                    $entry_points[] = $variant_url;
                }
            }

            return $entry_points;
        }

        // Fallback to utility method
        return $this->experiment_utility->get_experiment_entry_points($experiment, null);
    }

    /**
     * Get variant URL
     *
     * @since 0.0.1
     *
     * @param object $variant Variant object.
     * @param object $experiment Experiment object.
     * @return string|null Variant URL or null if not available
     */
    protected function get_variant_url($variant, $experiment)
    {
        return $this->experiment_utility->get_variant_url($variant, $experiment);
    }

    /**
     * Get weighted random variant
     *
     * @since 0.0.1
     *
     * @param array $variants Variants.
     * @return object|null
     */
    public function get_weighted_random_variant($variants)
    {
        return $this->experiment_utility->get_weighted_random_variant($variants);
    }

    /**
     * Get test variant from URL parameters
     *
     * @since 0.0.1
     *
     * @return array|null Test variant data or null if not found
     */
    protected function get_test_variant_from_url()
    {
        // Test/preview mode requires editor or admin capability.
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            return null;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- URL parameters for A/B test variant selection, not form submissions

        // Check for new short format first (eid & vid)
        if (isset($_GET['eid']) && isset($_GET['vid'])) {
            $experiment_uuid = sanitize_text_field(wp_unslash($_GET['eid']));
            $variant_uuid = sanitize_text_field(wp_unslash($_GET['vid']));

            return array(
                'param' => 'eid_vid',
                'identifier' => $variant_uuid,
                'experiment_uuid' => $experiment_uuid,
                'variant_uuid' => $variant_uuid
            );
        }

        // Check for various URL parameter formats (backward compatibility)
        $test_params = array(
            'sigmize_test_variant',
            'test_variant',
            'variant',
            'sigmize_variant'
        );

        foreach ($test_params as $param) {
            if (isset($_GET[$param]) && !empty($_GET[$param])) {
                $variant_identifier = sanitize_text_field(wp_unslash($_GET[$param]));

                return array(
                    'param' => $param,
                    'identifier' => $variant_identifier
                );
            }
        }

        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return null;
    }

    /**
     * Handle test mode redirect
     *
     * @since 0.0.1
     *
     * @param array $test_variant Test variant data from URL
     * @return void
     */
    protected function handle_test_mode_redirect($test_variant)
    {
        // Get all active experiments with variants
        $experiments_with_variants = $this->get_active_experiments_with_variants();

        if (empty($experiments_with_variants)) {
            return;
        }

        $current_url = $this->url_matcher->get_current_url();

        // Find matching experiment and variant based on test parameters
        foreach ($experiments_with_variants as $experiment_data) {
            $experiment = $experiment_data['experiment'];
            $variants = $experiment_data['variants'];

            // If experiment_uuid specified, filter to that experiment
            if (
                !empty($test_variant['experiment_uuid']) &&
                $experiment->uuid !== $test_variant['experiment_uuid']
            ) {
                continue;
            }

            // Find the specified variant
            $target_variant = null;
            if ($test_variant['param'] === 'eid_vid') {
                foreach ($variants as $variant) {
                    if ($variant->uuid === $test_variant['variant_uuid']) {
                        $target_variant = $variant;
                        break;
                    }
                }
            } else {
                $target_variant = $this->find_variant_by_identifier($variants, $test_variant['identifier']);
            }

            if (!$target_variant) {
                continue;
            }

            // Inject test variant data and redirect if needed
            $variant_url = $this->get_variant_url($target_variant, $experiment);
            if ($variant_url && !$this->url_matcher->url_matches_pattern($current_url, $variant_url)) {
                // Note: No assignment cookie created - let SDK handle all assignments

                // Inject variant data
                $this->inject_selected_variant_data_cookie($target_variant, $experiment);

                // Remove test parameters and redirect
                $clean_variant_url = $this->remove_test_params_from_url($variant_url);
                $redirect_url = $this->preserve_query_params($current_url, $clean_variant_url);
                wp_safe_redirect($redirect_url, 302);
                exit;
            }

            // If already on correct URL, just inject data
            if ($variant_url && $this->url_matcher->url_matches_pattern($current_url, $variant_url)) {
                // Note: No assignment cookie created - let SDK handle all assignments
                $this->inject_selected_variant_data_cookie($target_variant, $experiment);
            }

            return;
        }
    }

    /**
     * Get cookie domain for subdomain support
     *
     * @since 0.0.1
     *
     * @return string Cookie domain (empty string for current domain, or .domain.com for subdomains).
     */
    protected function get_cookie_domain()
    {
        // Subdomain cookies are enabled by default
        // No need to fetch from database - always enabled

        // Get the current domain
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';

        if (empty($host)) {
            return '';
        }

        // Remove port if present
        $host = explode(':', $host)[0];

        // For localhost or IP addresses, don't set domain
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        // Extract main domain (e.g., example.com from sub.example.com)
        $parts = explode('.', $host);

        if (count($parts) < 2) {
            return '';
        }

        // Return domain with leading dot for subdomain support
        if (count($parts) >= 2) {
            $main_domain = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
            return '.' . $main_domain;
        }

        return '';
    }

    /**
     * Find variant by identifier (name, slug, or UUID)
     *
     * @since 0.0.1
     *
     * @param array $variants Array of variants
     * @param string $identifier Variant identifier
     * @return object|null Variant object or null if not found
     */
    protected function find_variant_by_identifier($variants, $identifier)
    {
        $identifier = strtolower(trim($identifier));

        foreach ($variants as $variant) {
            // Check by UUID
            if ($identifier === strval($variant->uuid)) {
                return $variant;
            }

            // Check by slug
            if (!empty($variant->slug) && $identifier === strtolower($variant->slug)) {
                return $variant;
            }

            // Check by name (case-insensitive)
            if ($identifier === strtolower($variant->name)) {
                return $variant;
            }

            // Check for common aliases
            if ($variant->is_control) {
                if (in_array($identifier, array('control', 'original', 'a', '0'))) {
                    return $variant;
                }
            }
        }

        return null;
    }

    /**
     * Remove test parameters from URL
     *
     * @since 0.0.1
     *
     * @param string $url URL to clean
     * @return string Clean URL
     */
    protected function remove_test_params_from_url($url)
    {
        $test_params = array('eid', 'vid', 'sigmize_test_variant', 'test_variant', 'variant', 'sigmize_variant', 'sigmize_disabled');

        $parsed_url = wp_parse_url($url);
        if (!isset($parsed_url['query'])) {
            return $url;
        }

        parse_str($parsed_url['query'], $query_params);

        // Remove test parameters
        foreach ($test_params as $param) {
            unset($query_params[$param]);
        }

        // Rebuild URL
        $clean_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if (isset($parsed_url['port'])) {
            $clean_url .= ':' . $parsed_url['port'];
        }
        if (isset($parsed_url['path'])) {
            $clean_url .= $parsed_url['path'];
        }
        if (!empty($query_params)) {
            $clean_url .= '?' . http_build_query($query_params);
        }
        if (isset($parsed_url['fragment'])) {
            $clean_url .= '#' . $parsed_url['fragment'];
        }

        return $clean_url;
    }

    /**
     * Preserve query parameters from current URL when redirecting to variant URL
     *
     * @since 0.0.1
     *
     * @param string $current_url Original URL with query parameters
     * @param string $variant_url Variant URL to redirect to
     * @return string Variant URL with preserved query parameters
     */
    protected function preserve_query_params($current_url, $variant_url)
    {
        if (empty($current_url) || empty($variant_url)) {
            return $variant_url;
        }

        // Parse both URLs
        $current_parsed = wp_parse_url($current_url);
        $variant_parsed = wp_parse_url($variant_url);

        if (! $current_parsed || ! $variant_parsed) {
            return $variant_url;
        }

        // If current URL has no query parameters, return variant URL as-is
        if (empty($current_parsed['query'])) {
            return $variant_url;
        }

        // Parse existing query parameters from both URLs
        $current_params = array();
        $variant_params = array();

        if (! empty($current_parsed['query'])) {
            parse_str($current_parsed['query'], $current_params);
        }

        if (! empty($variant_parsed['query'])) {
            parse_str($variant_parsed['query'], $variant_params);
        }

        // Merge parameters - variant URL parameters take precedence over current URL
        $merged_params = array_merge($current_params, $variant_params);

        // Remove test parameters that shouldn't be preserved
        $test_params_to_remove = array('sigmize_test_experiment', 'sigmize_test_variant');
        foreach ($test_params_to_remove as $param) {
            unset($merged_params[$param]);
        }

        // Rebuild the variant URL with merged query parameters
        $result_url = '';

        // Add scheme
        if (! empty($variant_parsed['scheme'])) {
            $result_url .= $variant_parsed['scheme'] . '://';
        }

        // Add host
        if (! empty($variant_parsed['host'])) {
            $result_url .= $variant_parsed['host'];
        }

        // Add port
        if (! empty($variant_parsed['port'])) {
            $result_url .= ':' . $variant_parsed['port'];
        }

        // Add path
        if (! empty($variant_parsed['path'])) {
            $result_url .= $variant_parsed['path'];
        }

        // Add merged query parameters
        if (! empty($merged_params)) {
            $result_url .= '?' . http_build_query($merged_params);
        }

        // Add fragment from variant URL
        if (! empty($variant_parsed['fragment'])) {
            $result_url .= '#' . $variant_parsed['fragment'];
        }

        return $result_url;
    }

    /**
     * Verify the HMAC signature on tracking URL parameters.
     *
     * @since 0.0.11
     *
     * @param string $eid Experiment UUID.
     * @param string $aid Assignment UUID.
     * @param string $vid Variant UUID.
     * @param string $sig Provided HMAC-SHA256 signature (hex).
     * @return bool True when the signature is valid, false otherwise.
     */
    private function verify_tracking_signature($eid, $aid, $vid, $sig)
    {
        if (empty($sig)) {
            return false;
        }

        $bearer_token = (new Encryption())->decrypt(get_option('sigmize_bearer_token'));

        if (empty($bearer_token)) {
            return false;
        }

        $expected = hash_hmac('sha256', $eid . '|' . $aid . '|' . $vid, $bearer_token);

        return hash_equals($expected, $sig);
    }

    /**
     * Validate that the experiment and variant UUIDs exist in the local cache.
     *
     * @since 0.0.11
     *
     * @param string $eid Experiment UUID.
     * @param string $vid Variant UUID.
     * @return bool True when both exist and the experiment is running.
     */
    private function validate_assignment_params($eid, $vid)
    {
        $cache = get_option('sigmize_experiments', array());

        if (empty($cache['experiments']) || ! is_array($cache['experiments'])) {
            return false;
        }

        if (! isset($cache['experiments'][ $eid ])) {
            return false;
        }

        $experiment_data = $cache['experiments'][ $eid ];

        // Resolve experiment object (may be stored as array or object).
        $experiment = isset($experiment_data['experiment']) ? $experiment_data['experiment'] : null;
        if (empty($experiment)) {
            return false;
        }

        if (is_array($experiment)) {
            $experiment = (object) $experiment;
        }

        // Confirm status is running (handle both array and object formats).
        if (isset($experiment->status)) {
            $status_value = is_array($experiment->status) ? $experiment->status['value'] : $experiment->status->value;
        } else {
            return false;
        }

        if (empty($status_value) || $status_value !== 'running') {
            return false;
        }

        // Confirm variant UUID exists.
        $variants = isset($experiment_data['variants']) ? $experiment_data['variants'] : array();
        foreach ($variants as $variant) {
            if (is_array($variant)) {
                $variant = (object) $variant;
            }
            if (isset($variant->uuid) && $variant->uuid === $vid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle sigmize_aid, sigmize_eid, and sigmize_vid query parameters and set SDK-compatible assignment cookies
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function handle_sigmize_aid_parameter()
    {
        // Skip if in admin area or other contexts where we shouldn't process parameters
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- URL parameters for A/B test assignment from external source, not form submissions

        // Get URL parameters
        $sigmize_aid = isset($_GET['sigmize_aid']) ? sanitize_text_field(wp_unslash($_GET['sigmize_aid'])) : null;
        $sigmize_eid = isset($_GET['sigmize_eid']) ? sanitize_text_field(wp_unslash($_GET['sigmize_eid'])) : null;
        $sigmize_vid = isset($_GET['sigmize_vid']) ? sanitize_text_field(wp_unslash($_GET['sigmize_vid'])) : null;
        $sigmize_sig = isset($_GET['sigmize_sig']) ? sanitize_text_field(wp_unslash($_GET['sigmize_sig'])) : null;

        // Check if we have the minimum required parameters
        if (! $sigmize_eid || (! $sigmize_aid && ! $sigmize_vid)) {
            return;
        }

        $experiment_uuid = $sigmize_eid;
        $assignment_uuid = $sigmize_aid;
        $variant_uuid = $sigmize_vid;

        if ($experiment_uuid && $assignment_uuid && $variant_uuid) {
            // Layer 1: reject requests with a missing or invalid HMAC signature.
            if (! $this->verify_tracking_signature($experiment_uuid, $assignment_uuid, $variant_uuid, $sigmize_sig)) {
                $this->redirect_without_sigmize_params();
                return;
            }

            // Layer 2: reject requests that reference an unknown experiment or variant.
            if (! $this->validate_assignment_params($experiment_uuid, $variant_uuid)) {
                $this->redirect_without_sigmize_params();
                return;
            }

            // Create SDK-compatible assignment cookie format
            // Cookie name: sigmize_assignment_{experiment_uuid}
            $cookie_name = 'sigmize_assignment_' . $experiment_uuid;

            // Cookie value: {"a": "assignment_uuid", "v": "variant_uuid", "e": timestamp}
            $assignment_data = array(
                'a' => $assignment_uuid,   // assignment UUID
                'v' => $variant_uuid,      // variant UUID  
                'e' => time() + 600  // expiration timestamp
            );

            $cookie_value = wp_json_encode($assignment_data);
            $expiry = time() + 600;

            // Set cookie with proper domain and security settings
            $cookie_domain = $this->get_cookie_domain();
            setcookie($cookie_name, $cookie_value, array(
                'expires' => $expiry,
                'path' => '/',
                'domain' => $cookie_domain,
                'secure' => is_ssl(),
                'httponly' => false, // Must be false - frontend JS SDK needs to read this cookie
                'samesite' => 'Lax'
            ));

            // Also set for immediate use in current request
            $_COOKIE[$cookie_name] = $cookie_value;
        }

        // Clean up URL by removing all sigmize parameters
        if (! empty($_GET['sigmize_aid']) || ! empty($_GET['sigmize_eid']) || ! empty($_GET['sigmize_vid']) || ! empty($_GET['sigmize_disabled']) || ! empty($_GET['sigmize_sig'])) {
            $this->redirect_without_sigmize_params();
        }

        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Redirect to current URL without sigmize_aid and sigmize_eid parameters
     *
     * @since 0.0.1
     *
     * @return void
     */
    protected function redirect_without_sigmize_params()
    {
        // Get current URL
        $http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $current_url = (is_ssl() ? 'https://' : 'http://') . $http_host . $request_uri;

        // Parse URL and remove parameters
        $parsed_url = wp_parse_url($current_url);
        if (! isset($parsed_url['query'])) {
            return;
        }

        parse_str($parsed_url['query'], $query_params);

        // Remove sigmize parameters
        unset($query_params['sigmize_aid']);
        unset($query_params['sigmize_eid']);
        unset($query_params['sigmize_vid']);
        unset($query_params['sigmize_disabled']);
        unset($query_params['sigmize_sig']);

        // Rebuild URL
        $clean_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if (isset($parsed_url['port'])) {
            $clean_url .= ':' . $parsed_url['port'];
        }
        if (isset($parsed_url['path'])) {
            $clean_url .= $parsed_url['path'];
        }
        if (! empty($query_params)) {
            $clean_url .= '?' . http_build_query($query_params);
        }
        if (isset($parsed_url['fragment'])) {
            $clean_url .= '#' . $parsed_url['fragment'];
        }

        // Perform redirect
        wp_safe_redirect($clean_url, 302);
        exit;
    }
}
