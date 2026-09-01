<?php

/**
 * Autoloader class
 *
 * @package Sigmize
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader class
 */
class Sigmize_Autoloader
{

    /**
     * Namespace prefix
     *
     * @var string
     */
    private $namespace_prefix = 'Sigmize\\';

    /**
     * Base directory for the namespace prefix
     *
     * @var string
     */
    private $base_dir;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->base_dir = SIGMIZE_PLUGIN_PATH . 'includes/';
        $this->register();
    }

    /**
     * Register the autoloader
     */
    public function register()
    {
        spl_autoload_register(array($this, 'autoload'));
    }

    /**
     * Autoload callback
     *
     * @param string $class The fully-qualified class name.
     * @return void
     */
    public function autoload($class)
    {
        // Does the class use the namespace prefix?
        $len = strlen($this->namespace_prefix);
        if (strncmp($this->namespace_prefix, $class, $len) !== 0) {
            // No, move to the next registered autoloader.
            return;
        }

        // Get the relative class name.
        $relative_class = substr($class, $len);

        // Security: Validate class name contains only allowed characters
        if (! preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative_class)) {
            return;
        }

        // Replace namespace separator with directory separator.
        $file = str_replace('\\', '/', $relative_class);

        // Replace underscore with hyphen.
        $file = str_replace('_', '-', $file);

        // Convert class name to lowercase.
        $file = strtolower($file);

        // Security: Prevent directory traversal
        if (strpos($file, '..') !== false || strpos($file, './') !== false) {
            return;
        }

        // Determine file prefix based on type
        $file_parts = explode('/', $file);
        $class_name = array_pop($file_parts);

        // Security: Validate each directory part
        foreach ($file_parts as $part) {
            if (empty($part) || ! preg_match('/^[a-z0-9\-]+$/', $part)) {
                return;
            }
        }

        // Security: Validate class name
        if (empty($class_name) || ! preg_match('/^[a-z0-9\-]+$/', $class_name)) {
            return;
        }

        // Check if it's an interface or regular class
        if (strpos($class_name, 'interface') === 0 || strpos($relative_class, 'Interface') !== false) {
            // Interface file (already has interface- prefix)
            $file_parts[] = $class_name;
        } else {
            // Regular class file needs class- prefix
            $file_parts[] = 'class-' . $class_name;
        }

        $file = implode('/', $file_parts);

        // Get the full path to the file.
        $full_path = $this->base_dir . $file . '.php';

        // Security: Resolve real path and ensure it's within base directory
        $real_base = realpath($this->base_dir);
        $real_file = realpath(dirname($full_path));

        // Check if file exists and is within allowed directory
        if (
            $real_base &&
            $real_file &&
            strpos($real_file, $real_base) === 0 &&
            file_exists($full_path) &&
            is_readable($full_path)
        ) {
            require_once $full_path;
        }
    }
}

// Initialize the autoloader.
new Sigmize_Autoloader();
