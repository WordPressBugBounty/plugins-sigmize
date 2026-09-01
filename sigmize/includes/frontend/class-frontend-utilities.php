<?php

/**
 * Frontend Utilities
 *
 * Consolidated utility classes for frontend functionality
 *
 * @package Sigmize
 */

namespace Sigmize\Frontend;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * URL Matcher Implementation
 * 
 * Implements URL matching functionality.
 */
class URL_Matcher_Impl implements Interface_URL_Matcher
{

    /**
     * Get current URL
     *
     * @return string
     */
    public function get_current_url()
    {
        // Use WordPress method to get current URL if available, including query params
        if (function_exists('home_url') && ! empty($GLOBALS['wp'])) {
            // Get the base URL
            $url = home_url($GLOBALS['wp']->request);

            // Add query parameters if they exist
            if (! empty($_SERVER['QUERY_STRING'])) {
                $query_string = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING'])) : '';
                if ($query_string) {
                    $url .= '?' . $query_string;
                }
            }
        } else {
            // Fallback to $_SERVER method (includes query params in REQUEST_URI)
            $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
            $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

            // Include protocol
            $protocol = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';

            // Combine protocol, host and URI (URI already includes query params)
            $url = $protocol . $host . $uri;
        }

        return apply_filters('sigmize_current_url', $url);
    }

    /**
     * Check if a URL matches a pattern
     *
     * @param string $url URL to check.
     * @param string $pattern Pattern to match against.
     * @return bool
     */
    public function url_matches_pattern($url, $pattern)
    {
        // Simple and clean URL normalization and exact matching
        $normalized_url = $this->clean_normalize_url($url);
        $normalized_pattern = $this->clean_normalize_url($pattern);

        return $normalized_url === $normalized_pattern;
    }

    /**
     * Clean and normalize URL for exact matching
     * 
     * Implementation per user specification:
     * - Remove trailing slash from both urls
     * - Remove any query params or hash from url
     * - Convert it to lower case
     * - Remove http:// https:// http://www. https://www. from both url
     * - Then do exact matching
     *
     * @param string $url URL to normalize.
     * @return string Normalized URL.
     */
    private function clean_normalize_url($url)
    {
        // Handle null or empty values
        if (empty($url)) {
            return '';
        }

        // Ensure it's a string
        $url = (string) $url;

        // Remove query params and hash
        $url = preg_replace('#[\?\#].*$#', '', $url);

        // Convert to lowercase
        $url = strtolower($url);

        // Remove protocols and www
        $url = preg_replace('#^https?://(www\.)?#i', '', $url);

        // Remove trailing slash
        $url = rtrim($url, '/');

        return $url;
    }
}

/**
 * Experiment Utility Class
 * 
 * Provides shared functionality for experiment-related operations.
 */
class Experiment_Utility
{

    /**
     * URL Matcher
     *
     * @var Interface_URL_Matcher
     */
    protected $url_matcher;

    /**
     * Constructor
     *
     * @param Interface_URL_Matcher $url_matcher URL Matcher.
     */
    public function __construct(Interface_URL_Matcher $url_matcher)
    {
        $this->url_matcher = $url_matcher;
    }

    /**
     * Get all active experiments
     *
     * @param string $priority Optional. Filter experiments by priority. Default empty (all priorities).
     * @return array Array of active experiments
     */
    public function get_active_experiments($priority = '')
    {
        // Get experiments from SaaS cache
        $cached_data = get_option('sigmize_experiments', array());

        // Ensure cached_data is an array
        if (!is_array($cached_data)) {
            $cached_data = array();
        }

        $experiments = array();

        if (! empty($cached_data['experiments'])) {
            foreach ($cached_data['experiments'] as $cached_experiment) {
                $exp_data = isset($cached_experiment['experiment']) ? $cached_experiment['experiment'] : $cached_experiment;

                // Filter by priority if specified
                if (! empty($priority) && isset($exp_data->priority) && $exp_data->priority !== $priority) {
                    continue;
                }

                // Only include running experiments
                if (isset($exp_data->status['value']) && $exp_data->status['value'] === 'running') {
                    $experiments[] = $exp_data;
                }
            }
        }

        /**
         * Filter the active experiments.
         *
         * @param array  $experiments The array of active experiments.
         * @param string $priority    The priority filter, if any.
         */
        return apply_filters('sigmize_active_experiments', $experiments, $priority);
    }

    /**
     * Get variants for an experiment
     *
     * @param int $experiment_id Experiment ID.
     * @return array Array of variants
     */
    public function get_experiment_variants($experiment_id)
    {
        // Check if SaaS is connected and use cached data
        $auth_manager = new \Sigmize\Auth_Manager();
        if ($auth_manager->is_authenticated()) {
            $cached_data = get_option('sigmize_experiments', array());

            // Ensure cached_data is an array
            if (!is_array($cached_data)) {
                $cached_data = array();
            }

            if (! empty($cached_data['experiments'])) {
                foreach ($cached_data['experiments'] as $cached_experiment) {
                    $exp_data = isset($cached_experiment['experiment']) ? $cached_experiment['experiment'] : $cached_experiment;

                    // Match by UUID or numeric ID
                    $exp_uuid = $exp_data->uuid ?? null;
                    $exp_id = $exp_data->id ?? null;

                    if (($exp_uuid && $exp_uuid === $experiment_id) ||
                        ($exp_id && $exp_id === $experiment_id) ||
                        (is_numeric($experiment_id) && is_numeric($exp_id) && intval($exp_id) === intval($experiment_id))
                    ) {

                        // Return variants from cache
                        return isset($cached_experiment['variants']) ? $cached_experiment['variants'] : array();
                    }
                }
            }

            // If not found in cache, return empty array for SaaS mode
            return array();
        }

        // If not connected to SaaS, return empty array
        return array();
    }

    /**
     * Decode JSON content object
     *
     * @param mixed $content_object Content object or JSON string.
     * @return array|null Decoded content object or null if invalid
     */
    public function decode_content_object($content_object)
    {
        if (empty($content_object)) {
            return null;
        }

        // If it's already a decoded object, use it directly
        if (is_array($content_object) || is_object($content_object)) {
            return $content_object;
        }

        // Otherwise, decode the JSON string
        $decoded = json_decode($content_object, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Get variant URL
     *
     * @param object $variant Variant object.
     * @param object $experiment Experiment object.
     * @return string|null Variant URL or null if not available
     */
    public function get_variant_url($variant, $experiment)
    {

        if (isset($variant->content_value)) {
            return $variant->content_value;
        }
        return null;
    }

    /**
     * Get experiment entry points
     *
     * @param object $experiment Experiment object.
     * @param array  $variants   Optional. Pre-fetched variants to avoid duplicate queries.
     * @return array Array of entry point URLs
     */
    public function get_experiment_entry_points($experiment, $variants = null)
    {
        $entry_points = array();

        // Use provided variants or fetch them if not provided
        if (null === $variants) {
            $variants = $this->get_experiment_variants($experiment->uuid);
        }

        if (! empty($variants)) {
            foreach ($variants as $variant) {
                $variant_url = $this->get_variant_url($variant, $experiment);
                if ($variant_url) {
                    $entry_points[] = $variant_url;
                }
            }
        }

        return $entry_points;
    }

    /**
     * Get weighted random variant
     *
     * @param array $variants Variants.
     * @return object|null
     */
    public function get_weighted_random_variant($variants)
    {
        if (empty($variants)) {
            return null;
        }

        // Get random number between 0 and 99.999999
        // Using high precision to ensure fair distribution
        $random = random_int(0, 99999999) / 1000000.0;

        // Find variant based on cumulative traffic percentages
        $cumulative = 0;
        foreach ($variants as $variant) {
            $cumulative += (float) $variant->traffic_percentage;

            if ($random <= $cumulative) {
                return $variant;
            }
        }

        // If we reach here, it means:
        // 1. Total traffic percentage < 100% and random fell in the unallocated range
        // 2. All variants have 0% traffic
        // Return null to indicate no variant should be shown
        return null;
    }

    /**
     * Get URL matcher
     *
     * @return Interface_URL_Matcher
     */
    public function get_url_matcher()
    {
        return $this->url_matcher;
    }
}
