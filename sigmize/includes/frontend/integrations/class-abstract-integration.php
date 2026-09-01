<?php

/**
 * Abstract Integration Base Class
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend\Integrations;

use Sigmize\Frontend\Interface_Integration;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Abstract Integration Class
 *
 * Base class for all plugin integrations. Provides common functionality
 * and enforces implementation of required methods.
 */
abstract class AbstractIntegration implements Interface_Integration
{

    /**
     * Integration type identifier
     *
     * @var string
     */
    protected $integration_type = '';

    /**
     * Supported events for this integration
     *
     * @var array
     */
    protected $supported_events = array();

    /**
     * Whether the integration's plugin is active
     *
     * @var bool|null
     */
    protected $is_plugin_active = null;

    /**
     * Constructor
     *
     * @since 0.0.1
     */
    public function __construct()
    {
        $this->integration_type = $this->get_integration_type();
        $this->supported_events = $this->get_supported_events();
    }

    /**
     * Get integration type identifier
     *
     * @return string
     */
    abstract protected function get_integration_type();

    /**
     * Get supported events for this integration
     *
     * @return array Array of supported event triggers
     */
    abstract protected function get_supported_events();

    /**
     * Check if the integration's plugin is active
     *
     * @return bool
     */
    abstract public function is_plugin_active();

    /**
     * Initialize hooks for specific events
     *
     * @param array $required_events Array of event triggers that need hooks
     * @return void
     */
    abstract public function initialize_hooks($required_events = array());

    /**
     * Format event data for tracking
     *
     * @param string $event_trigger The event trigger that fired
     * @param array  $raw_data Raw event data from the hook
     * @return array Formatted event data
     */
    abstract protected function format_event_data($event_trigger, $raw_data = array());

    /**
     * Get the integration type
     *
     * @since 0.0.1
     *
     * @return string
     */
    public function get_type()
    {
        return $this->integration_type;
    }

    /**
     * Get all supported events
     *
     * @since 0.0.1
     *
     * @return array
     */
    public function get_events()
    {
        return $this->supported_events;
    }

    /**
     * Check if an event is supported by this integration
     *
     * @since 0.0.1
     *
     * @param string $event_trigger Event trigger to check
     * @return bool
     */
    public function supports_event($event_trigger)
    {
        foreach ($this->supported_events as $event) {
            if (isset($event['value']) && $event['value'] === $event_trigger) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the integration is active
     *
     * @since 0.0.1
     *
     * @return bool
     */
    public function is_active()
    {
        return $this->is_plugin_active();
    }

    /**
     * Get the integration ID
     *
     * @since 0.0.1
     *
     * @return string
     */
    public function get_id()
    {
        return $this->integration_type;
    }

    /**
     * Get the integration display name
     *
     * @since 0.0.1
     *
     * @return string
     */
    public function get_display_name()
    {
        // Convert integration type to display name
        switch ($this->integration_type) {
            case 'easy_digital_downloads':
                return __('Easy Digital Downloads', 'sigmize');
            case 'surecart':
                return __('SureCart', 'sigmize');
            case 'woocommerce':
                return __('WooCommerce', 'sigmize');
            case 'custom':
                return __('Custom Events', 'sigmize');
            default:
                // Fallback: convert underscores to spaces and capitalize words
                return ucwords(str_replace('_', ' ', $this->integration_type));
        }
    }

    /**
     * Track an integration event
     *
     * This is the main method called by WordPress hooks to track events.
     *
     * @since 0.0.1
     *
     * @param string $event_trigger Event trigger
     * @param mixed  ...$args Arguments passed from the WordPress hook
     * @return void
     */
    public function track_event($event_trigger, ...$args)
    {
        // Check if this integration supports the event
        if (! $this->supports_event($event_trigger)) {
            return;
        }

        // Format the raw hook arguments into standardized event data
        $event_data = $this->format_event_data($event_trigger, $args);

        // Get experiments that need this specific event
        $experiments = $this->get_relevant_experiments($event_trigger);

        if (empty($experiments)) {
            return;
        }

        // Handle both single event data and array of event data
        if (! empty($event_data)) {
            // Check if event_data is an array of events (multiple products) or single event
            $events_to_track = array();

            if (isset($event_data[0]) && is_array($event_data[0])) {
                // Multiple events (array of arrays)
                $events_to_track = $event_data;
            } else {
                // Single event (single array)
                $events_to_track = array($event_data);
            }

            // Track each event for each matching experiment
            foreach ($experiments as $experiment) {
                foreach ($events_to_track as $single_event_data) {
                    $this->send_tracking_event($experiment, $event_trigger, $single_event_data);
                }
            }
        }
    }

    /**
     * Get experiments that require tracking for this specific event
     *
     * @since 0.0.1
     *
     * @param string $event_trigger Event trigger
     * @return array Array of experiment objects
     */
    protected function get_relevant_experiments($event_trigger)
    {
        // Get the cached experiments from the integration registry
        $cache_key = $this->integration_type . '|' . $event_trigger;

        // Try to get from IntegrationRegistry cache
        $registry = IntegrationRegistry::get_instance();
        return $registry->get_cached_experiments($cache_key);
    }

    /**
     * Send tracking event for a specific experiment
     *
     * @since 0.0.1
     *
     * @param object $experiment Experiment object
     * @param string $event_trigger Event trigger
     * @param array  $event_data Formatted event data
     * @return void
     */
    protected function send_tracking_event($experiment, $event_trigger, $event_data)
    {
        // Get experiment UUID
        $experiment_uuid = isset($experiment->uuid) ? $experiment->uuid : null;

        if (empty($experiment_uuid)) {
            return; // No experiment UUID, can't track
        }

        // Check if sigmize_assignment cookie exists
        if (! isset($_COOKIE['sigmize_assignment_' . $experiment_uuid])) {
            return; // No sigmize_assignment cookie found, can't track
        }

        // Parse the sigmize_assignment cookie
        $assignment_data = $this->parse_assignment_cookie($experiment_uuid);
        if (! $assignment_data) {
            return;
        }

        // Get or create session ID
        $session_id = $this->get_session_id();

        // Get goal UUID
        $goal_uuids = $this->get_goal_uuid($experiment, $event_trigger);

        foreach ($goal_uuids as $goal_uuid) {
            if (!$goal_uuid) {
                continue;
            }

            // Prepare base metadata
            $metadata = array(
                'page'            => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '',
                'goal_uuid'       => $goal_uuid,
                'category'        => 'custom_events',
                'integration_type' => $this->integration_type
            );

            $metadata = array_merge($metadata, $event_data);

            // Prepare request data
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
            $data = array(
                'experiment_uuid' => $experiment_uuid,
                'assignment_uuid' => $assignment_data['assignment_uuid'],
                'session_id'      => $session_id,
                'event_type'      => 'conversion',
                'metadata'        => $metadata,
                'page_url'        => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : site_url($request_uri),
                'referrer_url'    => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : ''
            );

            // Send tracking request
            $this->send_tracking_request($data);
        }
    }

    /**
     * Parse assignment cookie data
     *
     * @since 0.0.1
     *
     * @param string $experiment_uuid Experiment UUID
     * @return array|false Assignment data or false on failure
     */
    protected function parse_assignment_cookie($experiment_uuid)
    {
        $cookie_name = 'sigmize_assignment_' . $experiment_uuid;

        // Check if cookie exists
        if (!isset($_COOKIE[$cookie_name])) {
            return false;
        }

        try {
            // Get cookie value - strip non-JSON chars as whitelist sanitization
            // Note: We already checked isset() above, so cookie exists at this point
            $raw_cookie = wp_unslash($_COOKIE[$cookie_name]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on next line with whitelist regex
            $sanitized_cookie = preg_replace('/[^a-zA-Z0-9\/+=\{\}\[\]:,"_\-\.\s]/', '', $raw_cookie);

            $assignment_data = json_decode($sanitized_cookie, true);
        } catch (\Exception $e) {
            return false; // Invalid JSON in cookie
        }

        // Check if assignment data is valid
        if (! is_array($assignment_data) || empty($assignment_data)) {
            return false;
        }

        // Check if assignment data has required fields
        if (! isset($assignment_data['a']) || ! isset($assignment_data['v']) || ! isset($assignment_data['e'])) {
            return false;
        }

        // Check if assignment is not expired
        if ($assignment_data['e'] <= time()) {
            return false;
        }

        return array(
            'assignment_uuid' => sanitize_text_field($assignment_data['a']),
            'variant_uuid'    => sanitize_text_field($assignment_data['v']),
            'expires'         => $assignment_data['e']
        );
    }

    /**
     * Get or create session ID
     *
     * Uses cookies for WordPress compatibility instead of PHP sessions.
     *
     * @since 0.0.1
     *
     * @return string
     */
    protected function get_session_id()
    {
        $cookie_name = 'sigmize_session_id';

        // Check if session ID cookie exists
        if (isset($_COOKIE[$cookie_name])) {
            return sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
        }

        // Generate new session ID
        $session_id = 'sigmize_sid_' . uniqid('', true) . '_' . current_time('timestamp');

        // Set cookie for 30 days
        if (!headers_sent()) {
            setcookie(
                $cookie_name,
                $session_id,
                time() + (30 * DAY_IN_SECONDS),
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true // httponly
            );
        }

        return $session_id;
    }

    /**
     * Get goal UUID for the specific event trigger
     *
     * @since 0.0.1
     *
     * @param object $experiment Experiment object
     * @param string $event_trigger Event trigger
     * @return array Goal UUID
     */
    protected function get_goal_uuid($experiment, $event_trigger)
    {
        // Check for goals
        $goals = isset($experiment->goals) && is_array($experiment->goals) ? $experiment->goals : null;

        if (empty($goals)) {
            return [];
        }

        $goalUuids = [];

        // Get and normalize current domain
        $current_domain = $this->normalize_domain(
            isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : ''
        );

        // Find matching goal
        foreach ($goals as $goal) {
            // Convert goal to array if it's an object
            $goal = (array) $goal;

            // Handle different goal type formats
            $current_goal_type = null;
            if (isset($goal['type'])) {
                if (is_array($goal['type']) && isset($goal['type']['value'])) {
                    $current_goal_type = $goal['type']['value'];
                } elseif (is_string($goal['type'])) {
                    $current_goal_type = $goal['type'];
                }
            }

            // Skip if goal type is not custom_events
            if ($current_goal_type !== 'custom_events') {
                continue;
            }

            $goal_config = (array) ($goal['config'] ?? array());

            // Check if this goal matches our integration type and event trigger
            $integration_match = isset($goal_config['integrationType']) &&
                $goal_config['integrationType'] === $this->integration_type;
            $trigger_match = isset($goal_config['eventTrigger']) &&
                $goal_config['eventTrigger'] === $event_trigger;

            // Check domain match if domain is specified in goal config
            $domain_match = true;
            if (isset($goal_config['domain']) && !empty($goal_config['domain'])) {
                $goal_domain = $this->normalize_domain($goal_config['domain']);
                $domain_match = ($goal_domain === $current_domain);
            }

            // Return UUID if integration, trigger, and domain all match
            if ($integration_match && $trigger_match && $domain_match) {
                $goalUuids[] = $goal['uuid'] ?? null;
            }
        }

        return $goalUuids;
    }

    /**
     * Normalize domain for comparison
     *
     * Removes protocol, www, trailing slashes, query params, etc.
     *
     * @since 0.0.7
     *
     * @param string $domain Domain to normalize
     * @return string Normalized domain
     */
    protected function normalize_domain($domain)
    {
        // Handle null or empty values
        if (empty($domain)) {
            return '';
        }

        // Ensure it's a string
        $domain = (string) $domain;

        // Remove query params and hash
        $domain = preg_replace('#[\?\#].*$#', '', $domain);

        // Convert to lowercase
        $domain = strtolower($domain);

        // Remove protocols and www
        $domain = preg_replace('#^https?://(www\.)?#i', '', $domain);

        // Remove trailing slash
        $domain = rtrim($domain, '/');

        return $domain;
    }

    /**
     * Send tracking request to the API
     *
     * @since 0.0.1
     *
     * @param array $data Request data
     * @return bool Success status
     */
    protected function send_tracking_request($data)
    {
        try {
            // API endpoint
            $api_url = SIGMIZE_SAAS_API_BASE_URL . '/api/v1/track/event';

            // Prepare request arguments
            $args = array(
                'method'      => 'POST',
                'timeout'     => 30,
                'redirection' => 5,
                'httpversion' => '1.1',
                'blocking'    => false,
                'headers'     => array(
                    'Content-Type' => 'application/json',
                ),
                'body'        => wp_json_encode($data),
                'cookies'     => array(),
            );

            // Send request
            $response = wp_remote_post($api_url, $args);

            // Return false on error
            if (is_wp_error($response)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
