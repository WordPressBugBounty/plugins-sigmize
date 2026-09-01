<?php

/**
 * Frontend Manager
 *
 * Manages frontend experiment functionality and SDK integration.
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Frontend Manager class
 *
 * Coordinates experiment data injection, SDK loading, and integration
 * with e-commerce platforms for comprehensive A/B testing.
 */
class Frontend_Manager implements Interface_Module
{

    /**
     * Traffic Redirector
     *
     * @var Traffic_Redirector
     */
    protected $traffic_redirector;

    /**
     * URL Matcher
     *
     * @var Interface_URL_Matcher
     */
    protected $url_matcher;

    /**
     * Integration Event Tracker
     *
     * @var Integration_Event_Tracker
     */
    protected $integration_event_tracker;

    /**
     * Whether the GDPR consent cookie was found to be tampered at template_redirect.
     *
     * Set to true when verify_gdpr_consent_cookie() fails at template_redirect so
     * enqueue_scripts() knows to inject the JS localStorage purge without re-reading
     * $_COOKIE (which is cleared by delete_gdpr_consent_cookie()).
     *
     * @since 0.0.11
     *
     * @var bool
     */
    protected $gdpr_cookie_tampered = false;

    /**
     * Constructor
     *
     * @since 0.0.1
     *
     * @param Interface_URL_Matcher $url_matcher URL Matcher.
     */
    public function __construct(
        Interface_URL_Matcher $url_matcher) {
        $this->url_matcher = $url_matcher;

        // Initialize Experiment Utility
        $experiment_utility = new Experiment_Utility($url_matcher);

        // Initialize Traffic Redirector
        $this->traffic_redirector = new Traffic_Redirector(
            $url_matcher,
            $experiment_utility
        );

        // Initialize Integration Event Tracker
        $this->integration_event_tracker = new Integration_Event_Tracker();
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
        // Initialize sub-modules
        $this->traffic_redirector->init();
        $this->integration_event_tracker->init();

        // Register our hooks
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
        add_action('template_redirect', array($this, 'expire_tampered_gdpr_cookie'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 1);
    }

    /**
     * Expire a tampered GDPR consent cookie before headers are sent.
     *
     * Runs on template_redirect (before any output) so setcookie() reliably
     * adds the Set-Cookie header. Sets $gdpr_cookie_tampered to true so
     * enqueue_scripts() can inject the JS localStorage purge without
     * re-reading $_COOKIE (cleared by delete_gdpr_consent_cookie()).
     *
     * @since 0.0.11
     *
     * @return void
     */
    public function expire_tampered_gdpr_cookie()
    {
        $gdpr_enabled = get_option('sigmize_gdpr_enabled', false);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! $gdpr_enabled || ! isset($_COOKIE['sigmize_gdpr_consent'])) {
            return;
        }

        $cookie_manager = \Sigmize\Secure_Cookie_Manager::get_instance();
        if (! $cookie_manager->verify_gdpr_consent_cookie()) {
            $this->gdpr_cookie_tampered = true;
            $cookie_manager->delete_gdpr_consent_cookie();
        }
    }

    /**
     * Enqueue frontend scripts and styles
     *
     * @since 0.0.1
     *
     * @return void
     */
    public function enqueue_scripts()
    {
        // Skip if in admin
        if (is_admin()) {
            return;
        }

        // Check if experiments are disabled via sigmize_disabled parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter to disable experiments, not a form submission
        if (isset($_GET['sigmize_disabled'])) {
            // Skip loading experiment SDK scripts - similar to JS SDK behavior
            return;
        }

        // Check if manual SDK loading is enabled
        $manual_sdk_loading = get_option('sigmize_manual_sdk_loading', false);
        if ($manual_sdk_loading) {
            // User wants to load SDK manually - skip automatic loading
            return;
        }

        // Check if this is a WordPress search request
        // Skip experiments on search results to ensure search functionality works correctly
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- URL parameter for search query, not a form submission
        if (isset($_GET['s'])) {
            // Skip all experiments on search results pages
            return;
        }

        $workspace_uuid = sanitize_text_field(get_option('sigmize_workspace_uuid', ''));
        if (empty($workspace_uuid)) {
            return;
        }
        $sdk_url = SIGMIZE_SAAS_API_BASE_URL . '/sdk/ws/' . $workspace_uuid . '.js';

        // Enqueue with no dependencies and load in head for anti-flicker
        wp_enqueue_script(
            'sigmize-sdk-' . $workspace_uuid,
            $sdk_url,
            array(),
            SIGMIZE_VERSION,
            false // Load in head for anti-flicker
        );

        // Expose the GDPR consent endpoint so the SDK can POST to it
        wp_add_inline_script(
            'sigmize-sdk-' . $workspace_uuid,
            'window.sigmizeGdprConsentUrl = ' . wp_json_encode(
                rest_url('sigmize/v1/gdpr/consent')
            ) . ';',
            'before'
        );

        // When the GDPR consent cookie was detected as tampered at template_redirect,
        // purge any stale consent and assignment data synchronously — before the SDK
        // script executes — so the SDK treats the visitor as not yet consented (showing
        // the consent popup) rather than redirecting on a cryptographically invalid token.
        // The Set-Cookie expiry header was already sent at template_redirect; this JS
        // purge clears localStorage and any client-visible cookies in the same pass.
        if ($this->gdpr_cookie_tampered) {
            wp_add_inline_script(
                'sigmize-sdk-' . $workspace_uuid,
                '(function(){' .
                    'localStorage.removeItem("sigmize_gdpr_consent");' .
                    'document.cookie.split(";").forEach(function(c){' .
                        'var n=c.trim().split("=")[0];' .
                        'if(n==="sigmize_gdpr_consent"||' .
                           'n.indexOf("sigmize_assignment_")===0||' .
                           'n.indexOf("sigmize_temp_assignment_")===0){' .
                            'document.cookie=n+"=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;";' .
                        '}' .
                    '});' .
                '})();',
                'before'
            );
        }
    }
}
