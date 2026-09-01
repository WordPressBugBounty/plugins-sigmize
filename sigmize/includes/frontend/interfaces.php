<?php

/**
 * Frontend Interfaces
 *
 * All frontend interfaces consolidated in one file
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Module Interface
 */
interface Interface_Module
{

    /**
     * Initialize the module
     *
     * @return void
     */
    public function init();

    /**
     * Register hooks
     *
     * @return void
     */
    public function register_hooks();
}

/**
 * URL Matcher Interface
 *
 * Defines methods for URL matching functionality.
 */
interface Interface_URL_Matcher
{

    /**
     * Get current URL
     *
     * @return string
     */
    public function get_current_url();

    /**
     * Check if a URL matches a pattern
     *
     * @param string $url URL to check.
     * @param string $pattern Pattern to match against.
     * @return bool
     */
    public function url_matches_pattern($url, $pattern);
}

/**
 * Integration Interface
 *
 * Defines methods for plugin integrations functionality.
 */
interface Interface_Integration
{

    /**
     * Get integration type identifier
     *
     * @return string
     */
    public function get_type();

    /**
     * Get supported events for this integration
     *
     * @return array
     */
    public function get_events();

    /**
     * Check if the integration's plugin is active
     *
     * @return bool
     */
    public function is_plugin_active();

    /**
     * Initialize hooks for specific events
     *
     * @param array $required_events Array of event triggers that need hooks
     * @return void
     */
    public function initialize_hooks($required_events = array());

    /**
     * Check if an event is supported by this integration
     *
     * @param string $event_trigger Event trigger to check
     * @return bool
     */
    public function supports_event($event_trigger);

    /**
     * Track an integration event
     *
     * @param string $event_trigger Event trigger
     * @param mixed  ...$args Arguments passed from the WordPress hook
     * @return void
     */
    public function track_event($event_trigger, ...$args);
}

/**
 * Admin Module Interface
 *
 * Defines methods for admin functionality modules.
 */
interface Interface_Admin_Module
{

    /**
     * Register admin menu pages
     *
     * @return void
     */
    public function register_menu();

    /**
     * Enqueue admin assets (scripts and styles)
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_assets($hook);
}
