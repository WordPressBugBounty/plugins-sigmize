<?php

/**
 * Admin Menu class
 *
 * @package Sigmize
 */

namespace Sigmize\Admin;

use Sigmize\Auth_Manager;
use Sigmize\Frontend\Interface_Admin_Module;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin Menu class
 */
class Admin_Menu implements Interface_Admin_Module
{

    /**
     * Menu slug
     *
     * @var string
     */
    private $menu_slug = 'sigmize';

    /**
     * Auth manager instance
     *
     * @var Auth_Manager
     */
    private $auth_manager;

    /**
     * Constructor
     *
     * @since 0.0.1
     */
    public function __construct()
    {
        $this->auth_manager = new Auth_Manager();

        // Register menu immediately instead of hooking to admin_menu
        $this->register_menu();
        // Enqueue our styles/scripts late so our CSS wins over WP admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'), 100);
    }

    /**
     * Register admin menu
     *
     * @since 0.0.1
     */
    public function register_menu()
    {

        $logo = file_get_contents(SIGMIZE_PLUGIN_PATH . 'assets/images/sigmize-icon.svg');
        // Main menu (single page)
        add_menu_page(
            __('Sigmize', 'sigmize'),
            __('Sigmize', 'sigmize'),
            'manage_options',
            $this->menu_slug . '-dashboard',
            array($this, 'render_dashboard_page'),
            $logo ? 'data:image/svg+xml;base64,' . base64_encode($logo) : 'dashicons-randomize',
            30
        );

        // Optional: also add a visible Dashboard submenu (mirrors main page)
        add_submenu_page(
            $this->menu_slug . '-dashboard',
            __('Dashboard', 'sigmize'),
            __('Dashboard', 'sigmize'),
            'manage_options',
            $this->menu_slug . '-dashboard',
            array($this, 'render_dashboard_page')
        );
    }

    /**
     * Enqueue admin assets
     *
     * @since 0.0.1
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets($hook)
    {
        // Only load on our plugin pages
        if (strpos($hook, $this->menu_slug) === false) {
            return;
        }

        // Enqueue Tailwind CSS
        wp_enqueue_style(
            'sigmize-tailwind',
            SIGMIZE_PLUGIN_URL . 'assets/css/tailwind.css',
            array(),
            SIGMIZE_VERSION
        );

        // Enqueue React app. wp-element declares react and react-dom as its
        // dependencies, so WordPress loads the React runtime automatically and
        // the bundle never ships its own copy.
        wp_enqueue_script(
            'sigmize-admin',
            SIGMIZE_PLUGIN_URL . 'assets/js/admin.js',
            array('wp-element', 'wp-i18n', 'wp-api-fetch'),
            SIGMIZE_VERSION,
            true
        );

        // Load JS translations for the React bundle.
        wp_set_script_translations('sigmize-admin', 'sigmize', SIGMIZE_PLUGIN_PATH . 'languages');

        // Localize script — bearer token and SaaS URLs intentionally excluded;
        // all SaaS API calls are proxied through nonce-protected REST endpoints.
        // REST root URL and nonce are supplied by the wp-api-fetch script (its
        // root-URL and nonce middleware), so they are not duplicated here.
        $localized_data = array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'adminUrl'  => admin_url('admin.php'),
            'siteUrl'   => home_url(),
            'menuSlug'  => $this->menu_slug,
            'currentPage' => $hook,
            'isAuthenticated' => $this->auth_manager->is_authenticated(),
            'authUrl'   => $this->auth_manager->get_auth_url(),
            'pluginsUrl' => admin_url('plugins.php'),
            'pluginUrl' => SIGMIZE_PLUGIN_URL,
            'hasAuthError' => $this->auth_manager->has_auth_error(),
            'isRtl'         => is_rtl(),
        );

        wp_localize_script(
            'sigmize-admin',
            'sigmizeAdmin',
            $localized_data
        );
    }

    /**
     * Render React app container
     */
    private function render_react_app()
    {
        $dir = is_rtl() ? 'rtl' : 'ltr';
        // Check if authenticated
        if (! $this->auth_manager->is_authenticated()) {
            echo '<div id="sigmize-app" dir="' . esc_attr( $dir ) . '" data-page="auth"></div>';
        } else {
            echo '<div id="sigmize-app" dir="' . esc_attr( $dir ) . '"></div>';
        }
    }

    /**
     * Render dashboard page
     *
     * @since 0.0.1
     */
    public function render_dashboard_page()
    {
        $this->render_react_app();
    }
}
