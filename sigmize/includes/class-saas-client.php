<?php

/**
 * SaaS API Client
 *
 * @package Sigmize
 */

namespace Sigmize;

use WP_Error;

/**
 * Class SaaS_Client
 *
 * Handles communication with the external SaaS API
 */
class SaaS_Client
{

    /**
     * API base URL
     *
     * @var string
     */
    private $api_base_url = SIGMIZE_SAAS_API_BASE_URL . '/api/v1';

    /**
     * Auth Manager instance
     *
     * @var Auth_Manager
     */
    private $auth_manager;

    /**
     * Constructor
     *
     * @since 0.0.1
     *
     * @param Auth_Manager $auth_manager Optional. Auth manager instance.
     */
    public function __construct(?Auth_Manager $auth_manager = null)
    {
        $this->auth_manager = $auth_manager ?: new Auth_Manager();
    }

    /**
     * Get default headers for API requests
     *
     * @return array
     */
    private function get_default_headers()
    {
        $bearer_token = $this->auth_manager->get_bearer_token();

        if (! $bearer_token) {
            return array(
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            );
        }

        return array(
            'Authorization' => 'Bearer ' . $bearer_token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        );
    }

    /**
     * Make a GET request to the SaaS API
     *
     * @since 0.0.1
     *
     * @param string $endpoint The API endpoint (relative to base URL).
     * @param array  $args     Optional. Additional arguments for wp_remote_get.
     * @return array|WP_Error The response or WP_Error on failure.
     */
    public function get($endpoint, $args = array())
    {
        try {
            $url = $this->api_base_url . '/' . ltrim($endpoint, '/');

            $default_args = array(
                'headers' => $this->get_default_headers(),
                'timeout' => 30,
            );

            $args = wp_parse_args($args, $default_args);

            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

            if (200 !== $code) {
                return new WP_Error(
                    'saas_api_error',
                    sprintf(
                        /* translators: %d: HTTP status code returned by the API. */
                        __('API returned status code %d', 'sigmize'),
                        $code
                    ),
                    array('body' => $body, 'code' => $code)
                );
            }

            $data = json_decode($body, true);

            if (null === $data && ! empty($body)) {
                return new WP_Error('saas_api_invalid_json', __('Invalid JSON response from API', 'sigmize'));
            }

            return $data;
        } catch (\Exception $e) {
            return new WP_Error(
                'saas_api_exception',
                sprintf(
                    /* translators: %s: error message detail. */
                    __('Exception during API request: %s', 'sigmize'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Make a POST request to the SaaS API
     *
     * @since 0.0.1
     *
     * @param string $endpoint The API endpoint (relative to base URL).
     * @param array  $data     The data to send.
     * @param array  $args     Optional. Additional arguments for wp_remote_post.
     * @return array|WP_Error The response or WP_Error on failure.
     */
    public function post($endpoint, $data = array(), $args = array())
    {
        try {
            $url = $this->api_base_url . '/' . ltrim($endpoint, '/');

            $default_args = array(
                'headers' => $this->get_default_headers(),
                'body'    => wp_json_encode($data),
                'timeout' => 30,
            );

            $args = wp_parse_args($args, $default_args);

            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

            if (! in_array($code, array(200, 201), true)) {
                return new WP_Error(
                    'saas_api_error',
                    sprintf(
                        /* translators: %d: HTTP status code returned by the API. */
                        __('API returned status code %d', 'sigmize'),
                        $code
                    ),
                    array('body' => $body, 'code' => $code)
                );
            }

            $data = json_decode($body, true);

            if (null === $data && ! empty($body)) {
                return new WP_Error('saas_api_invalid_json', __('Invalid JSON response from API', 'sigmize'));
            }

            return $data;
        } catch (\Exception $e) {
            return new WP_Error(
                'saas_api_exception',
                sprintf(
                    /* translators: %s: error message detail. */
                    __('Exception during API request: %s', 'sigmize'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Make a PUT request to the SaaS API
     *
     * @since 0.0.1
     *
     * @param string $endpoint The API endpoint (relative to base URL).
     * @param array  $data     The data to send.
     * @param array  $args     Optional. Additional arguments for wp_remote_request.
     * @return array|WP_Error The response or WP_Error on failure.
     */
    public function put($endpoint, $data = array(), $args = array())
    {
        try {
            $url = $this->api_base_url . '/' . ltrim($endpoint, '/');

            $default_args = array(
                'method'  => 'PUT',
                'headers' => $this->get_default_headers(),
                'body'    => wp_json_encode($data),
                'timeout' => 30,
            );

            $args = wp_parse_args($args, $default_args);

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

            if (200 !== $code) {
                return new WP_Error(
                    'saas_api_error',
                    sprintf(
                        /* translators: %d: HTTP status code returned by the API. */
                        __('API returned status code %d', 'sigmize'),
                        $code
                    ),
                    array('body' => $body, 'code' => $code)
                );
            }

            $data = json_decode($body, true);

            if (null === $data && ! empty($body)) {
                return new WP_Error('saas_api_invalid_json', __('Invalid JSON response from API', 'sigmize'));
            }

            return $data;
        } catch (\Exception $e) {
            return new WP_Error(
                'saas_api_exception',
                sprintf(
                    /* translators: %s: error message detail. */
                    __('Exception during API request: %s', 'sigmize'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Make a DELETE request to the SaaS API
     *
     * @since 0.0.1
     *
     * @param string $endpoint The API endpoint (relative to base URL).
     * @param array  $args     Optional. Additional arguments for wp_remote_request.
     * @return array|WP_Error The response or WP_Error on failure.
     */
    public function delete($endpoint, $args = array())
    {
        try {
            $url = $this->api_base_url . '/' . ltrim($endpoint, '/');

            $default_args = array(
                'method'  => 'DELETE',
                'headers' => $this->get_default_headers(),
                'timeout' => 30,
            );

            $args = wp_parse_args($args, $default_args);

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);

            if (! in_array($code, array(200, 204), true)) {
                $body = wp_remote_retrieve_body($response);
                return new WP_Error(
                    'saas_api_error',
                    sprintf(
                        /* translators: %d: HTTP status code returned by the API. */
                        __('API returned status code %d', 'sigmize'),
                        $code
                    ),
                    array('body' => $body, 'code' => $code)
                );
            }

            return true;
        } catch (\Exception $e) {
            return new WP_Error(
                'saas_api_exception',
                sprintf(
                    /* translators: %s: error message detail. */
                    __('Exception during API request: %s', 'sigmize'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Get all experiments from the SaaS API
     *
     * @since 0.0.1
     *
     * @return array|WP_Error
     */
    public function get_experiments()
    {
        return $this->get('experiments');
    }

    /**
     * Get a single experiment from the SaaS API
     *
     * @since 0.0.1
     *
     * @param string $experiment_uuid The experiment UUID.
     * @return array|WP_Error
     */
    public function get_experiment($experiment_uuid)
    {
        return $this->get('experiments/' . $experiment_uuid);
    }

    /**
     * Create a new experiment in the SaaS API
     *
     * @since 0.0.1
     *
     * @param array $experiment_data The experiment data.
     * @return array|WP_Error
     */
    public function create_experiment($experiment_data)
    {
        return $this->post('experiments', $experiment_data);
    }

    /**
     * Update an experiment in the SaaS API
     *
     * @since 0.0.1
     *
     * @param string $experiment_uuid The experiment UUID.
     * @param array $experiment_data The experiment data.
     * @return array|WP_Error
     */
    public function update_experiment($experiment_uuid, $experiment_data)
    {
        return $this->put('experiments/' . $experiment_uuid, $experiment_data);
    }

    /**
     * Delete an experiment from the SaaS API
     *
     * @since 0.0.1
     *
     * @param string $experiment_uuid The experiment UUID.
     * @return bool|WP_Error
     */
    public function delete_experiment($experiment_uuid)
    {
        return $this->delete('experiments/' . $experiment_uuid);
    }

    /**
     * Make a PATCH request to the SaaS API
     *
     * @since 0.0.1
     *
     * @param string $endpoint The API endpoint (relative to base URL).
     * @param array  $data     The data to send.
     * @param array  $args     Optional. Additional arguments for wp_remote_request.
     * @return array|WP_Error The response or WP_Error on failure.
     */
    public function patch($endpoint, $data = array(), $args = array())
    {
        try {
            $url = $this->api_base_url . '/' . ltrim($endpoint, '/');

            $default_args = array(
                'method'  => 'PATCH',
                'headers' => $this->get_default_headers(),
                'body'    => wp_json_encode($data),
                'timeout' => 30,
            );

            $args = wp_parse_args($args, $default_args);

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

            if (200 !== $code) {
                return new WP_Error(
                    'saas_api_error',
                    sprintf(
                        /* translators: %d: HTTP status code returned by the API. */
                        __('API returned status code %d', 'sigmize'),
                        $code
                    ),
                    array('body' => $body, 'code' => $code)
                );
            }

            $data = json_decode($body, true);

            if (null === $data && ! empty($body)) {
                return new WP_Error('saas_api_invalid_json', __('Invalid JSON response from API', 'sigmize'));
            }

            return $data;
        } catch (\Exception $e) {
            return new WP_Error(
                'saas_api_exception',
                sprintf(
                    /* translators: %s: error message detail. */
                    __('Exception during API request: %s', 'sigmize'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Update connection status with plugin version and experiments count
     *
     * @since 0.0.1
     *
     * @param string $workspace_uuid Workspace UUID.
     * @param string $plugin_version Plugin version.
     * @param int    $experiments_count Number of experiments synced.
     * @return array|WP_Error The response or WP_Error on failure.
     */
    public function update_connection_status($workspace_uuid, $plugin_version, $experiments_count)
    {
        return $this->patch('connections/status', array(
            'workspace_uuid' => $workspace_uuid,
            'plugin_version' => $plugin_version,
            'experiments_count' => $experiments_count,
            // This site is authoritative about where its own REST API lives.
            // A connection created by partner provisioning has this value
            // derived from the home URL, which is wrong whenever pretty
            // permalinks are off. Reporting the real one repairs it before the
            // platform dispatches a webhook to an address that never answers.
            'site_url' => rest_url('sigmize/v1/'),
        ));
    }
}
