<?php

/**
 * Daily Sync Manager
 *
 * Handles daily synchronization of plugin status to the SaaS API
 *
 * @package Sigmize
 */

namespace Sigmize;

/**
 * Class Daily_Sync_Manager
 *
 * Manages daily synchronization of plugin version and experiment count to the SaaS API
 */
class Daily_Sync_Manager
{

    /**
     * Cron hook name
     *
     * @var string
     */
    const CRON_HOOK = 'sigmize_daily_sync';

    /**
     * SaaS Client instance
     *
     * @var SaaS_Client
     */
    private $saas_client;

    /**
     * Constructor
     *
     * @since 0.0.1
     */
    public function __construct()
    {
        $this->saas_client = new SaaS_Client();
        $this->setup_hooks();
    }

    /**
     * Setup WordPress hooks
     *
     * @since 0.0.1
     */
    private function setup_hooks()
    {
        // Schedule the daily sync on plugin activation
        add_action('init', array($this, 'schedule_daily_sync'));

        // Hook the sync function to the cron event
        add_action(self::CRON_HOOK, array($this, 'perform_daily_sync'));

        // Clear scheduled event on plugin deactivation
        register_deactivation_hook(SIGMIZE_PLUGIN_PATH . 'sigmize.php', array($this, 'clear_scheduled_sync'));
    }

    /**
     * Schedule daily sync if not already scheduled
     *
     * @since 0.0.1
     */
    public function schedule_daily_sync()
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Clear scheduled sync event
     *
     * @since 0.0.1
     */
    public function clear_scheduled_sync()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Perform the daily sync to SaaS API
     *
     * @since 0.0.1
     */
    public function perform_daily_sync()
    {
        // Check if connected to SaaS platform
        $workspace_uuid = sanitize_text_field(get_option('sigmize_workspace_uuid'));
        $bearer_token = get_option('sigmize_bearer_token');

        if (empty($workspace_uuid) || empty($bearer_token)) {
            // Not connected, skip sync
            return;
        }

        // Get plugin version
        $plugin_version = SIGMIZE_VERSION;

        // Get experiment count from cached experiments
        $experiments_count = $this->get_experiments_count();

        // Send update to SaaS API
        $response = $this->saas_client->update_connection_status(
            $workspace_uuid,
            $plugin_version,
            $experiments_count
        );

        if (is_wp_error($response)) {
            return;
        }

        // Update last sync timestamp
        update_option('sigmize_last_status_sync', current_time('mysql'));
    }

    /**
     * Get the count of synced experiments
     *
     * @since 0.0.1
     * @return int Number of experiments
     */
    private function get_experiments_count()
    {
        $cache = get_option('sigmize_experiments', array());
        $experiments = isset($cache['experiments']) && is_array($cache['experiments'])
            ? $cache['experiments']
            : array();

        return count($experiments);
    }

    /**
     * Manually trigger connection status sync
     *
     * @since 0.0.1
     * @return array|WP_Error Response from API
     */
    public function trigger_manual_sync()
    {
        $workspace_uuid = sanitize_text_field(get_option('sigmize_workspace_uuid'));
        $plugin_version = SIGMIZE_VERSION;
        $experiments_count = $this->get_experiments_count();

        return $this->saas_client->update_connection_status(
            $workspace_uuid,
            $plugin_version,
            $experiments_count
        );
    }
}
