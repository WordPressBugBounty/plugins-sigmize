<?php

/**
 * Plugin Name: Sigmize
 * Plugin URI: https://sigmize.com
 * Description: A powerful A/B testing plugin for WordPress that enables testing of pages and elements with comprehensive analytics.
 * Version: 1.2.0
 * Author: Sigmize
 * Author URI: https://sigmize.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sigmize
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 7.4
 *
 * @package Sigmize
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}


/**
 * SaaS API Base URL constant
 */
if (! defined('SIGMIZE_SAAS_API_BASE_URL')) {
    define('SIGMIZE_SAAS_API_BASE_URL', 'https://api.sigmize.com');
}

/**
 * SaaS SDK Base URL constant
 */
if (! defined('SIGMIZE_SAAS_BASE_URL')) {
    define('SIGMIZE_SAAS_BASE_URL', 'https://app.sigmize.com');
}

/**
 * Main plugin class
 */
final class Sigmize
{

    /**
     * Plugin version
     *
     * @var string
     */
    const VERSION = '1.2.0';

    /**
     * Plugin singleton instance
     *
     * @var Sigmize
     */
    private static $instance = null;

    /**
     * Plugin directory path
     *
     * @var string
     */
    private $plugin_path;

    /**
     * Plugin directory URL
     *
     * @var string
     */
    private $plugin_url;

    /**
     * Get singleton instance
     *
     * @return Sigmize
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        $this->define_constants();
        $this->setup_hooks();
        $this->includes();
        $this->init();
    }

    /**
     * Define plugin constants
     */
    private function define_constants()
    {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);

        define('SIGMIZE_VERSION', self::VERSION);
        define('SIGMIZE_PLUGIN_PATH', $this->plugin_path);
        define('SIGMIZE_PLUGIN_URL', $this->plugin_url);
        define('SIGMIZE_PLUGIN_BASENAME', plugin_basename(__FILE__));
    }

    /**
     * Setup plugin hooks
     */
    private function setup_hooks()
    {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Load plugin translations.
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Add plugin action links
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));
    }

    /**
     * Load the plugin text domain for translations.
     *
     * @since 1.0.0
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('sigmize', false, dirname(SIGMIZE_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Include required files
     */
    private function includes()
    {
        // Include autoloader
        require_once SIGMIZE_PLUGIN_PATH . 'includes/class-autoloader.php';

        // Interfaces (must be loaded first for other classes to implement them)
        require_once SIGMIZE_PLUGIN_PATH . 'includes/frontend/interfaces.php';

        // Admin
        require_once SIGMIZE_PLUGIN_PATH . 'includes/admin/class-admin-menu.php';
        require_once SIGMIZE_PLUGIN_PATH . 'includes/admin/class-dashboard-widget.php';


        // Security
        require_once SIGMIZE_PLUGIN_PATH . 'includes/class-secure-cookie-manager.php';

        // Authentication
        require_once SIGMIZE_PLUGIN_PATH . 'includes/class-auth-manager.php';

        // SaaS Integration
        require_once SIGMIZE_PLUGIN_PATH . 'includes/class-saas-client.php';

        // Daily Sync Manager
        require_once SIGMIZE_PLUGIN_PATH . 'includes/class-daily-sync-manager.php';

        // REST API
        require_once SIGMIZE_PLUGIN_PATH . 'includes/api/class-rest-controller.php';

        // Abilities API
        require_once SIGMIZE_PLUGIN_PATH . 'includes/class-abilities-provider.php';

        // Frontend utilities
        require_once SIGMIZE_PLUGIN_PATH . 'includes/frontend/class-frontend-utilities.php';

        // Hybrid Architecture
        require_once SIGMIZE_PLUGIN_PATH . 'includes/frontend/class-integration-event-tracker.php';
        require_once SIGMIZE_PLUGIN_PATH . 'includes/frontend/class-traffic-redirector.php';
        require_once SIGMIZE_PLUGIN_PATH . 'includes/frontend/class-frontend-manager.php';
    }

    /**
     * Initialize plugin components
     */
    private function init()
    {
        // Initialize admin menu
        add_action('admin_menu', array($this, 'init_admin_menu'));

        // Overview card on the WordPress dashboard
        if (is_admin()) {
            new Sigmize\Admin\Dashboard_Widget();
        }

        // Initialize REST API
        add_action('rest_api_init', array($this, 'init_rest_api'));

        // Initialize frontend very early to ensure the same instance is used throughout
        add_action('init', array($this, 'init_frontend'), 1);

        // Initialize daily sync manager
        new Sigmize\Daily_Sync_Manager();

        // Initialize Abilities API integration
        $this->init_abilities();
    }

    /**
     * Initialize WordPress Abilities API integration.
     */
    public function init_abilities()
    {
        $abilities_provider = new Sigmize\Abilities_Provider();
        $abilities_provider->init();
    }

    /**
     * Initialize admin menu
     */
    public function init_admin_menu()
    {
        new Sigmize\Admin\Admin_Menu();
    }

    /**
     * Initialize REST API
     */
    public function init_rest_api()
    {
        $rest_controller = new Sigmize\API\Rest_Controller();
        $rest_controller->register_routes();
    }

    /**
     * Frontend manager instance
     *
     * @var Sigmize\Frontend\Frontend_Manager
     */
    public $frontend_manager = null;

    /**
     * Initialize frontend
     */
    public function init_frontend()
    {
        if (null === $this->frontend_manager) {
            // Create URL matcher and content type provider
            $url_matcher = new Sigmize\Frontend\URL_Matcher_Impl();
            // Initialize the hybrid Frontend_Manager
            $this->frontend_manager = new Sigmize\Frontend\Frontend_Manager(
                $url_matcher
            );
            $this->frontend_manager->init();
        }
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Clear permalinks
        flush_rewrite_rules();
    }


    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        flush_rewrite_rules();
    }

    /**
     * Add action links to plugin list
     *
     * @param array $links Existing plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_action_links($links)
    {
        // Check if plugin is connected to Sigmize
        $workspace_uuid = sanitize_text_field(get_option('sigmize_workspace_uuid', ''));
        $is_connected = !empty($workspace_uuid);

        // Show different link text based on connection status
        $link_text = $is_connected
            ? __('Access Dashboard', 'sigmize')
            : __('Get Started Now', 'sigmize');

        $dashboard_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=sigmize-dashboard'),
            $link_text
        );

        // Add our link to the beginning of the array
        array_unshift($links, $dashboard_link);

        return $links;
    }

    /**
     * Get plugin directory path
     *
     * @return string
     */
    public function get_plugin_path()
    {
        return $this->plugin_path;
    }

    /**
     * Get plugin directory URL
     *
     * @return string
     */
    public function get_plugin_url()
    {
        return $this->plugin_url;
    }
}

// Initialize the plugin
function sigmize()
{
    return Sigmize::get_instance();
}

// Start the plugin
sigmize();
