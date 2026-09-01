<?php

/**
 * Integration Registry
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend\Integrations;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Integration Registry Class
 * 
 * Manages all plugin integrations, handles registration, initialization,
 * and provides a centralized way to interact with integrations.
 */
class IntegrationRegistry
{

    /**
     * Singleton instance
     *
     * @var IntegrationRegistry|null
     */
    private static $instance = null;

    /**
     * Registered integrations
     *
     * @var AbstractIntegration[]
     */
    private $integrations = array();

    /**
     * Cached experiments by integration type and event trigger
     *
     * @var array
     */
    private $cached_experiments = array();

    /**
     * Required events by integration type
     *
     * @var array
     */
    private $required_events = array();

    /**
     * Constructor
     */
    private function __construct()
    {
        // Private constructor for singleton pattern
    }

    /**
     * Get singleton instance
     *
     * @return IntegrationRegistry
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register an integration
     *
     * @param AbstractIntegration $integration Integration instance
     * @return bool Success status
     */
    public function register_integration(AbstractIntegration $integration)
    {
        $type = $integration->get_type();

        if (empty($type)) {
            return false;
        }

        if (isset($this->integrations[$type])) {
            return false;
        }

        $this->integrations[$type] = $integration;

        return true;
    }

    /**
     * Get a registered integration by type
     *
     * @param string $type Integration type
     * @return AbstractIntegration|null
     */
    public function get_integration($type)
    {
        return isset($this->integrations[$type]) ? $this->integrations[$type] : null;
    }

    /**
     * Get all registered integrations
     *
     * @return AbstractIntegration[]
     */
    public function get_all_integrations()
    {
        return $this->integrations;
    }

    /**
     * Check if an integration type is registered
     *
     * @param string $type Integration type
     * @return bool
     */
    public function is_registered($type)
    {
        return isset($this->integrations[$type]);
    }

    /**
     * Initialize all active integrations
     *
     * This method analyzes active experiments to determine which integrations
     * need to be initialized and which events they need to track.
     *
     * @return void
     */
    public function initialize_integrations()
    {
        // Get required event types from active experiments
        $this->required_events = $this->analyze_required_events();

        // Initialize each integration that has required events and is active
        foreach ($this->integrations as $type => $integration) {
            // Check if integration's plugin is active
            if (! $integration->is_plugin_active()) {
                continue;
            }

            // Check if this integration has required events
            if (empty($this->required_events[$type])) {
                continue;
            }

            // Initialize hooks for required events
            $integration->initialize_hooks($this->required_events[$type]);
        }
    }

    /**
     * Analyze active experiments to determine required events
     *
     * @return array Array of required events grouped by integration type
     */
    private function analyze_required_events()
    {
        $required_events = array();

        // Initialize arrays for all registered integration types
        foreach ($this->integrations as $type => $integration) {
            $required_events[$type] = array();
        }

        // Clear previous cache
        $this->cached_experiments = array();

        // Get cached experiments data
        $cached_data = get_option('sigmize_experiments', array());

        // Ensure cached_data is an array
        if (!is_array($cached_data)) {
            $cached_data = array();
        }

        if (empty($cached_data['experiments'])) {
            return $required_events;
        }

        // Loop through experiments to find custom events and cache them
        foreach ($cached_data['experiments'] as $cached_experiment) {
            $exp_data = isset($cached_experiment['experiment']) ? $cached_experiment['experiment'] : $cached_experiment;

            // Skip if experiment is not active
            if (isset($exp_data->status['value']) && $exp_data->status['value'] !== 'running') {
                continue;
            }

            // Check for goals
            $goals = null;
            if (isset($exp_data->goals) && is_array($exp_data->goals)) {
                $goals = $exp_data->goals;
            } elseif (isset($cached_experiment['goals']) && is_array($cached_experiment['goals'])) {
                $goals = $cached_experiment['goals'];
            }

            if (empty($goals)) {
                continue;
            }

            // Extract custom event triggers from goals and cache experiments
            foreach ($goals as $goal) {
                $goal_type = $goal['type']['value'] ?? $goal->type ?? null;

                if ($goal_type !== 'custom_events') {
                    continue;
                }

                $goal_config = (array) ($goal['config'] ?? array());

                if (isset($goal_config['integrationType']) && isset($goal_config['eventTrigger'])) {
                    $integration_type = $goal_config['integrationType'];
                    $event_trigger = $goal_config['eventTrigger'];

                    // Only track events for registered integration types
                    if (! isset($required_events[$integration_type])) {
                        continue;
                    }

                    $required_events[$integration_type][] = $event_trigger;

                    // Cache the experiment for this specific integration_type + event_trigger combo
                    $cache_key = $integration_type . '|' . $event_trigger;
                    if (! isset($this->cached_experiments[$cache_key])) {
                        $this->cached_experiments[$cache_key] = array();
                    }

                    // Check if this experiment UUID is already cached to prevent duplicates
                    // This can happen when multiple goals have the same integration type and event trigger
                    $experiment_uuid = isset($exp_data->uuid) ? $exp_data->uuid : null;
                    $already_cached = false;

                    if ($experiment_uuid) {
                        foreach ($this->cached_experiments[$cache_key] as $cached_exp) {
                            if (isset($cached_exp->uuid) && $cached_exp->uuid === $experiment_uuid) {
                                $already_cached = true;
                                break;
                            }
                        }
                    }

                    // Only add if not already cached
                    if (!$already_cached) {
                        $this->cached_experiments[$cache_key][] = $exp_data;
                    }
                }
            }
        }

        // Remove duplicates from required events but keep the cache intact
        foreach ($required_events as $integration_type => $events) {
            $required_events[$integration_type] = array_unique($events);
        }

        return $required_events;
    }

    /**
     * Get cached experiments for a specific integration type and event trigger
     *
     * @param string $cache_key Cache key in format "integration_type|event_trigger"
     * @return array Array of experiment objects
     */
    public function get_cached_experiments($cache_key)
    {
        return isset($this->cached_experiments[$cache_key]) ? $this->cached_experiments[$cache_key] : array();
    }

    /**
     * Get required events for a specific integration type
     *
     * @param string $integration_type Integration type
     * @return array Array of required event triggers
     */
    public function get_required_events($integration_type)
    {
        return isset($this->required_events[$integration_type]) ? $this->required_events[$integration_type] : array();
    }

    /**
     * Get all required events grouped by integration type
     *
     * @return array
     */
    public function get_all_required_events()
    {
        return $this->required_events;
    }

    /**
     * Register default integrations
     *
     * This method registers all the built-in integrations.
     * Can be called during plugin initialization.
     *
     * @return void
     */
    public function register_default_integrations()
    {
        // Register EDD integration
        if (class_exists('\Sigmize\Frontend\Integrations\EddIntegration')) {
            $this->register_integration(new EddIntegration());
        }

        // Register SureCart integration
        if (class_exists('\Sigmize\Frontend\Integrations\SurecartIntegration')) {
            $this->register_integration(new SurecartIntegration());
        }

        // Register WooCommerce integration
        if (class_exists('\Sigmize\Frontend\Integrations\WoocommerceIntegration')) {
            $this->register_integration(new WoocommerceIntegration());
        }

        do_action('sigmize_register_integrations', $this);
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
