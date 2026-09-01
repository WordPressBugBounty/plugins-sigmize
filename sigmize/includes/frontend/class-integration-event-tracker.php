<?php

/**
 * Integration Event Tracker
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend;

use Sigmize\Frontend\Integrations\IntegrationRegistry;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Integration Event Tracker
 * 
 * Responsible for tracking events from integrated plugins like Easy Digital Downloads.
 * Now uses the modular integration system for better extensibility and maintainability.
 */
class Integration_Event_Tracker implements Interface_Module
{
    /**
     * Integration registry instance
     *
     * @var IntegrationRegistry
     */
    protected $registry;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Load integration classes first
        $this->load_integration_classes();

        // Check if the class exists before trying to use it
        if (!class_exists('Sigmize\Frontend\Integrations\IntegrationRegistry')) {
            return;
        }

        // Get the integration registry instance after classes are loaded
        $this->registry = IntegrationRegistry::get_instance();
    }

    /**
     * Initialize the module
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
     * @return void
     */
    public function register_hooks()
    {
        // Register default integrations and initialize them
        if ($this->registry) {
            $this->registry->register_default_integrations();
            $this->registry->initialize_integrations();
        }
    }

    /**
     * Load integration classes
     *
     * @return void
     */
    protected function load_integration_classes()
    {
        // Define the integrations directory
        $integrations_dir = plugin_dir_path(__FILE__) . 'integrations/';

        // Load integration classes (order matters - abstract class first)
        $integration_files = array(
            'class-abstract-integration.php',  // Load abstract class first
            'class-integration-registry.php',  // Then registry
            'class-edd-integration.php',       // Then concrete implementations
            'class-surecart-integration.php',
            'class-woocommerce-integration.php'
        );

        foreach ($integration_files as $file) {
            $file_path = $integrations_dir . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }

    /**
     * Get integration registry instance
     *
     * @return IntegrationRegistry
     */
    public function get_registry()
    {
        return $this->registry;
    }

    /**
     * Register a custom integration
     * 
     * Allows external plugins to register their own integrations.
     *
     * @param \Sigmize\Frontend\Integrations\AbstractIntegration $integration Integration instance
     * @return bool Success status
     */
    public function register_integration($integration)
    {
        return $this->registry->register_integration($integration);
    }


    /**
     * Get a registered integration by type
     *
     * @param string $type Integration type
     * @return \Sigmize\Frontend\Integrations\AbstractIntegration|null
     */
    public function get_integration($type)
    {
        return $this->registry->get_integration($type);
    }
    /**
     * Get all registered integrations
     *
     * @return \\Sigmize\\Frontend\\Integrations\\AbstractIntegration[]
     */
    public function get_all_integrations()
    {
        return $this->registry->get_all_integrations();
    }

    /**
     * Check if an integration is registered
     *
     * @param string $type Integration type
     * @return bool
     */
    public function is_integration_registered($type)
    {
        return $this->registry->is_registered($type);
    }

    /**
     * Get required events for all registered integrations
     *
     * @return array
     */
    public function get_required_events()
    {
        return $this->registry->get_all_required_events();
    }

    /**
     * Reinitialize integrations (useful when experiments change)
     *
     * @return void
     */
    public function reinitialize_integrations()
    {
        $this->registry->initialize_integrations();
    }
}
