<?php

/**
 * Abilities Provider class
 *
 * Registers Sigmize plugin capabilities with the WordPress Abilities API (WP 6.9+).
 * This enables AI agents, automation tools, and external systems to discover and
 * invoke Sigmize A/B testing functionality via the standardized REST endpoint.
 *
 * @package Sigmize
 * @since 0.0.11
 */

namespace Sigmize;

use WP_Error;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Abilities_Provider class
 *
 * Registers the `ab-testing` category and 7 Sigmize abilities with the
 * WordPress Abilities API, with backward-compatibility for WP < 6.9.
 *
 * @since 0.0.11
 */
class Abilities_Provider
{

    // =========================================================================
    // INIT
    // =========================================================================

    /**
     * Initialize the abilities provider.
     *
     * Bails silently on WordPress versions < 6.9 where the Abilities API
     * does not exist, preserving the WP 5.8+ minimum requirement.
     *
     * @since 0.0.11
     */
    public function init()
    {
        if (! function_exists('wp_register_ability_category')) {
            return;
        }

        add_action('wp_abilities_api_categories_init', array($this, 'register_category'));
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    // =========================================================================
    // REGISTRATION
    // =========================================================================

    /**
     * Register the `ab-testing` ability category.
     *
     * @since 0.0.11
     */
    public function register_category()
    {
        wp_register_ability_category(
            'sigmize',
            array(
                'label'       => __('Sigmize', 'sigmize'),
                'description' => __('Manage A/B testing experiments and configuration.', 'sigmize'),
            )
        );
    }

    /**
     * Register all Sigmize abilities.
     *
     * @since 0.0.11
     */
    public function register_abilities()
    {
        $this->register_connection_status();
        $this->register_list_experiments();
        $this->register_get_experiment();
        $this->register_request_sync();
        $this->register_cache_stats();
        $this->register_get_settings();
        $this->register_update_sdk_mode();
        $this->register_disconnect();
    }

    // =========================================================================
    // PERMISSION & CONNECTION HELPERS
    // =========================================================================

    /**
     * Shared permission callback for all abilities.
     *
     * @since 0.0.11
     * @return bool
     */
    public function check_admin_permission()
    {
        return current_user_can('manage_options');
    }

    /**
     * Return a WP_Error if the plugin is not connected to Sigmize.
     *
     * @since 0.0.11
     * @return WP_Error|null WP_Error when not connected, null when connected.
     */
    private function require_connection()
    {
        $workspace_uuid = sanitize_text_field(get_option('sigmize_workspace_uuid', ''));
        if (empty($workspace_uuid)) {
            return new WP_Error(
                'not_connected',
                __(
                    'Sigmize is not connected to a workspace. Please connect via the Sigmize dashboard (WP Admin → Sigmize) before using this ability.',
                    'sigmize'
                ),
                array('status' => 400)
            );
        }
        return null;
    }

    // =========================================================================
    // INDIVIDUAL ABILITY REGISTRATIONS
    // =========================================================================

    /**
     * Register the `sigmize/connection-status` ability.
     *
     * @since 0.0.11
     */
    private function register_connection_status()
    {
        wp_register_ability(
            'sigmize/connection-status',
            array(
                'label'               => __('Sigmize: Connection Status', 'sigmize'),
                'description'         => __('Returns the current Sigmize connection status, workspace UUID, connection ID, and last sync time.', 'sigmize'),
                'category'            => 'sigmize',
                'permission_callback' => array($this, 'check_admin_permission'),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
                'execute_callback'    => array($this, 'handle_connection_status'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                    'mcp'          => array('public' => true),
                ),
            )
        );
    }

    /**
     * Register the `sigmize/list-experiments` ability.
     *
     * @since 0.0.11
     */
    private function register_list_experiments()
    {
        wp_register_ability(
            'sigmize/list-experiments',
            array(
                'label'               => __('Sigmize: List Experiments', 'sigmize'),
                'description'         => __('Returns the locally-cached list of A/B testing experiments, optionally filtered by status.', 'sigmize'),
                'category'            => 'sigmize',
                'permission_callback' => array($this, 'check_admin_permission'),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'status' => array(
                            'type'        => 'string',
                            'description' => __('Filter experiments by status (e.g. "running").', 'sigmize'),
                        ),
                    ),
                ),
                'execute_callback'    => array($this, 'handle_list_experiments'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                    'mcp'          => array('public' => true),
                ),
            )
        );
    }

    /**
     * Register the `sigmize/get-experiment` ability.
     *
     * @since 0.0.11
     */
    private function register_get_experiment()
    {
        wp_register_ability(
            'sigmize/get-experiment',
            array(
                'label'               => __('Sigmize: Get Experiment', 'sigmize'),
                'description'         => __('Returns a single experiment from the local cache by its UUID.', 'sigmize'),
                'category'            => 'sigmize',
                'permission_callback' => array($this, 'check_admin_permission'),
                'input_schema'        => array(
                    'type'       => 'object',
                    'required'   => array('uuid'),
                    'properties' => array(
                        'uuid' => array(
                            'type'        => 'string',
                            'description' => __('The UUID of the experiment to retrieve.', 'sigmize'),
                        ),
                    ),
                ),
                'execute_callback'    => array($this, 'handle_get_experiment'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                    'mcp'          => array('public' => true),
                ),
            )
        );
    }

    /**
     * Register the `sigmize/request-sync` ability.
     *
     * @since 0.0.11
     */
    private function register_request_sync()
    {
        wp_register_ability(
            'sigmize/request-sync',
            array(
                'label'               => __('Sigmize: Request Sync', 'sigmize'),
                'description'         => __('Triggers an immediate sync that fetches all experiments from the remote Sigmize SaaS platform and overwrites the local experiment cache. Makes an outbound HTTP request to the Sigmize API. Safe to run multiple times — each run fetches the latest data. Avoid calling this repeatedly in quick succession.', 'sigmize'),
                'category'            => 'sigmize',
                'permission_callback' => array($this, 'check_admin_permission'),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
                'execute_callback'    => array($this, 'handle_request_sync'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                    'mcp'          => array('public' => true),
                ),
            )
        );
    }

    /**
     * Register the `sigmize/cache-stats` ability.
     *
     * @since 0.0.11
     */
    private function register_cache_stats()
    {
        wp_register_ability(
            'sigmize/cache-stats',
            array(
                'label'               => __('Sigmize: Cache Stats', 'sigmize'),
                'description'         => __('Returns statistics about the local experiments cache, including count and last sync time.', 'sigmize'),
                'category'            => 'sigmize',
                'permission_callback' => array($this, 'check_admin_permission'),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
                'execute_callback'    => array($this, 'handle_cache_stats'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                    'mcp'          => array('public' => true),
                ),
            )
        );
    }

    /**
     * Register the `sigmize/get-settings` ability.
     *
     * @since 0.0.11
     */
    private function register_get_settings()
    {
        wp_register_ability(
            'sigmize/get-settings',
            array(
                'label'               => __('Sigmize: Get Settings', 'sigmize'),
                'description'         => __('Returns the current Sigmize plugin settings including SDK loading mode and GDPR configuration. Call this before using update-sdk-mode to check the current state.', 'sigmize'),
                'category'            => 'sigmize',
                'permission_callback' => array($this, 'check_admin_permission'),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
                'execute_callback'    => array($this, 'handle_get_settings'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                    'mcp'          => array('public' => true),
                ),
            )
        );
    }

    /**
     * Register the `sigmize/update-sdk-mode` ability.
     *
     * @since 0.0.11
     */
    private function register_update_sdk_mode()
    {
        wp_register_ability(
            'sigmize/update-sdk-mode',
            array(
                'label'               => __('Sigmize: Update SDK Mode', 'sigmize'),
                'description'         => __('Enables or disables manual SDK loading mode for the Sigmize snippet. When enabled, the Sigmize JavaScript SDK is not automatically injected into pages — the site owner must load it manually. This directly affects whether A/B tests run on the frontend. Confirm with the user before changing, especially when disabling automatic loading, as it will stop all running experiments from functioning.', 'sigmize'),
                'category'            => 'sigmize',
                'permission_callback' => array($this, 'check_admin_permission'),
                'input_schema'        => array(
                    'type'       => 'object',
                    'required'   => array('manual_sdk_loading'),
                    'properties' => array(
                        'manual_sdk_loading' => array(
                            'type'        => 'boolean',
                            'description' => __('Whether to enable manual SDK loading.', 'sigmize'),
                        ),
                    ),
                ),
                'execute_callback'    => array($this, 'handle_update_sdk_mode'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ),
                    'show_in_rest' => true,
                    'mcp'          => array('public' => true),
                ),
            )
        );
    }

    /**
     * Register the `sigmize/disconnect` ability.
     *
     * @since 0.0.11
     */
    private function register_disconnect()
    {
        wp_register_ability(
            'sigmize/disconnect',
            array(
                'label'               => __('Sigmize: Disconnect', 'sigmize'),
                'description'         => __('Permanently disconnects the site from the Sigmize SaaS platform, deleting all authentication tokens, cached experiment data, and plugin settings. This cannot be undone — the user will need to reconnect and reconfigure from scratch. Always ask the user to explicitly confirm before running this.', 'sigmize'),
                'category'            => 'sigmize',
                'permission_callback' => array($this, 'check_admin_permission'),
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
                'execute_callback'    => array($this, 'handle_disconnect'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => false,
                        'destructive' => true,
                        'idempotent'  => false,
                    ),
                    'show_in_rest' => true,
                    'mcp'          => array('public' => true),
                ),
            )
        );
    }

    // =========================================================================
    // ABILITY HANDLERS
    // =========================================================================

    /**
     * Handle `sigmize/connection-status`.
     *
     * @since 0.0.11
     * @param mixed $input Ability input (unused).
     * @return array
     */
    public function handle_connection_status($input)
    {
        $input          = is_array($input) ? $input : array();
        $workspace_uuid = sanitize_text_field(get_option('sigmize_workspace_uuid', ''));
        $connected      = ! empty($workspace_uuid);

        return array(
            'connected'      => $connected,
            'workspace_uuid' => $connected ? $workspace_uuid : null,
            'connection_id'  => $connected ? sanitize_text_field(get_option('sigmize_connection_id', '')) : null,
            'last_sync_time' => get_option('sigmize_last_sync_time'),
        );
    }

    /**
     * Handle `sigmize/list-experiments`.
     *
     * @since 0.0.11
     * @param mixed $input Ability input. Optional key: `status` (string).
     * @return array|WP_Error
     */
    public function handle_list_experiments($input)
    {
        $input = is_array($input) ? $input : array();
        $error = $this->require_connection();
        if ($error !== null) {
            return $error;
        }

        $cache       = get_option('sigmize_experiments', array());
        $experiments = isset($cache['experiments']) && is_array($cache['experiments'])
            ? $cache['experiments']
            : array();

        $status_filter = isset($input['status']) ? sanitize_text_field($input['status']) : null;

        if ($status_filter !== null) {
            $experiments = array_filter(
                $experiments,
                function ($entry) use ($status_filter) {
                    if (! isset($entry['experiment'])) {
                        return false;
                    }

                    $experiment = $entry['experiment'];
                    $status_raw = is_object($experiment)
                        ? (isset($experiment->status) ? $experiment->status : null)
                        : (is_array($experiment) && isset($experiment['status']) ? $experiment['status'] : null);

                    if ($status_raw === null) {
                        return false;
                    }

                    $status_value = is_array($status_raw)
                        ? (isset($status_raw['value']) ? $status_raw['value'] : '')
                        : (is_object($status_raw) ? (isset($status_raw->value) ? $status_raw->value : '') : (string) $status_raw);

                    return $status_value === $status_filter;
                }
            );
        }

        return array(
            'experiments' => array_values($experiments),
            'count'       => count($experiments),
        );
    }

    /**
     * Handle `sigmize/get-experiment`.
     *
     * @since 0.0.11
     * @param mixed $input Ability input. Required key: `uuid` (string).
     * @return array|WP_Error
     */
    public function handle_get_experiment($input)
    {
        $input = is_array($input) ? $input : array();
        $error = $this->require_connection();
        if ($error !== null) {
            return $error;
        }

        if (empty($input['uuid'])) {
            return new WP_Error(
                'missing_uuid',
                __('The `uuid` input parameter is required.', 'sigmize'),
                array('status' => 400)
            );
        }

        $uuid        = sanitize_text_field($input['uuid']);
        $cache       = get_option('sigmize_experiments', array());
        $experiments = isset($cache['experiments']) && is_array($cache['experiments'])
            ? $cache['experiments']
            : array();

        if (isset($experiments[$uuid])) {
            return array(
                'found'      => true,
                'experiment' => $experiments[$uuid],
            );
        }

        return array(
            'found'      => false,
            'experiment' => null,
        );
    }

    /**
     * Handle `sigmize/request-sync`.
     *
     * Delegates to Rest_Controller::request_sync_from_saas() and returns
     * a trimmed response containing only the fields defined in the ability spec.
     *
     * @since 0.0.11
     * @param mixed $input Ability input (unused).
     * @return array|WP_Error
     */
    public function handle_request_sync($input)
    {
        $input = is_array($input) ? $input : array();
        $error = $this->require_connection();
        if ($error !== null) {
            return $error;
        }

        $rest_controller = new \Sigmize\API\Rest_Controller();
        $response        = $rest_controller->request_sync_from_saas(new \WP_REST_Request('POST'));

        if (is_wp_error($response)) {
            return $response;
        }

        $data = is_a($response, 'WP_REST_Response') ? $response->get_data() : (array) $response;

        return array(
            'success'      => isset($data['success']) ? (bool) $data['success'] : false,
            'message'      => isset($data['message']) ? $data['message'] : '',
            'synced_count' => isset($data['synced_count']) ? (int) $data['synced_count'] : 0,
        );
    }

    /**
     * Handle `sigmize/cache-stats`.
     *
     * @since 0.0.11
     * @param mixed $input Ability input (unused).
     * @return array
     */
    public function handle_cache_stats($input)
    {
        $input       = is_array($input) ? $input : array();
        $cache       = get_option('sigmize_experiments', array());
        $experiments = isset($cache['experiments']) && is_array($cache['experiments'])
            ? $cache['experiments']
            : array();

        $last_sync = get_option('sigmize_last_sync_time');

        return array(
            'experiments_count' => count($experiments),
            'last_sync_time'    => $last_sync,
            'formatted_time'    => $last_sync ? wp_date('Y-m-d H:i:s', strtotime($last_sync)) : null,
        );
    }

    /**
     * Handle `sigmize/get-settings`.
     *
     * @since 0.0.11
     * @param mixed $input Ability input (unused).
     * @return array
     */
    public function handle_get_settings($input)
    {
        $input = is_array($input) ? $input : array();
        return array(
            'manual_sdk_loading' => (bool) get_option('sigmize_manual_sdk_loading', false),
            'gdpr_enabled'       => (bool) get_option('sigmize_gdpr_enabled', false),
            'gdpr_settings'      => get_option('sigmize_gdpr_settings', array()),
        );
    }

    /**
     * Handle `sigmize/update-sdk-mode`.
     *
     * @since 0.0.11
     * @param mixed $input Ability input. Required key: `manual_sdk_loading` (bool).
     * @return array|WP_Error
     */
    public function handle_update_sdk_mode($input)
    {
        $input = is_array($input) ? $input : array();
        if (! isset($input['manual_sdk_loading'])) {
            return new WP_Error(
                'missing_parameter',
                __('The `manual_sdk_loading` input parameter is required.', 'sigmize'),
                array('status' => 400)
            );
        }

        $manual_sdk_loading = (bool) $input['manual_sdk_loading'];
        update_option('sigmize_manual_sdk_loading', $manual_sdk_loading);

        return array(
            'success'            => true,
            'manual_sdk_loading' => $manual_sdk_loading,
            'message'            => __('SDK loading setting updated successfully.', 'sigmize'),
        );
    }

    /**
     * Handle `sigmize/disconnect`.
     *
     * @since 0.0.11
     * @param mixed $input Ability input (unused).
     * @return array|WP_Error
     */
    public function handle_disconnect($input)
    {
        $input = is_array($input) ? $input : array();
        $error = $this->require_connection();
        if ($error !== null) {
            return $error;
        }

        $rest_controller = new \Sigmize\API\Rest_Controller();
        $response        = $rest_controller->disconnect_from_saas(new \WP_REST_Request('POST'));

        if (is_wp_error($response)) {
            return $response;
        }

        $data = is_a($response, 'WP_REST_Response') ? $response->get_data() : (array) $response;

        return array(
            'success' => isset($data['success']) ? (bool) $data['success'] : false,
            'message' => isset($data['message']) ? $data['message'] : '',
        );
    }
}
