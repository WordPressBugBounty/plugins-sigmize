<?php

/**
 * Secure Cookie Manager
 *
 * @package Sigmize
 */

namespace Sigmize;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Secure Cookie Manager class
 * 
 * Provides secure cookie storage with encryption, HMAC authentication,
 * and proper key management for sensitive data like auth tokens and assignments.
 */
class Secure_Cookie_Manager
{

    /**
     * Encryption method
     */
    private const CIPHER_METHOD = 'AES-256-GCM';

    /**
     * Authentication tag length for GCM mode
     */
    private const TAG_LENGTH = 16;

    /**
     * Cookie prefix for all secure cookies
     */
    private const COOKIE_PREFIX = 'sigmize_secure_';

    /**
     * Default cookie expiration in seconds (30 days)
     */
    private const DEFAULT_EXPIRY = 2592000;

    /**
     * Option name for storing encrypted keys
     */
    private const KEY_OPTION = 'sigmize_encryption_keys';

    /**
     * Cache group for keys
     */
    private const CACHE_GROUP = 'sigmize_secure_cookies';

    /**
     * Instance of the class
     *
     * @var Secure_Cookie_Manager|null
     */
    private static $instance = null;

    /**
     * Encryption keys cache
     *
     * @var array|null
     */
    private $keys_cache = null;

    /**
     * Get singleton instance
     *
     * @return Secure_Cookie_Manager
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get cookie domain for subdomain support
     *
     * @return string Cookie domain (empty string for current domain, or .domain.com for subdomains).
     */
    public function get_cookie_domain()
    {
        // Subdomain cookies are enabled by default
        // No need to fetch from database - always enabled

        // Get the current domain
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';

        if (empty($host)) {
            return '';
        }

        // Remove port if present
        $host = explode(':', $host)[0];

        // For localhost or IP addresses, don't set domain
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        // Extract main domain (e.g., example.com from sub.example.com)
        $parts = explode('.', $host);

        if (count($parts) < 2) {
            return '';
        }

        // Return domain with leading dot for subdomain support
        if (count($parts) >= 2) {
            $main_domain = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
            return '.' . $main_domain;
        }

        return '';
    }

    /**
     * Set an encrypted cookie
     *
     * @param string $name    Cookie name (will be prefixed).
     * @param mixed  $value   Value to encrypt (will be JSON encoded if not string).
     * @param int    $expiry  Expiration time in seconds from now.
     * @param array  $options Additional cookie options.
     * @return bool True on success, false on failure.
     */
    public function set_secure_cookie(string $name, $value, int $expiry = self::DEFAULT_EXPIRY, array $options = array())
    {
        try {

            // Convert non-string values to JSON
            if (! is_string($value)) {
                $value = wp_json_encode($value);
                if (false === $value) {
                    return false;
                }
            }

            // Get encryption key
            $keys = $this->get_encryption_keys();
            if (! $keys) {
                return false;
            }

            // Generate IV
            $iv_length = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = openssl_random_pseudo_bytes($iv_length);
            if (false === $iv) {
                return false;
            }

            // Encrypt the value
            $tag = '';
            $encrypted = openssl_encrypt(
                $value,
                self::CIPHER_METHOD,
                $keys['encryption_key'],
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if (false === $encrypted) {
                return false;
            }

            // Create payload with metadata
            $payload = array(
                'v' => 1, // Version for future compatibility
                'i' => base64_encode($iv),
                'd' => base64_encode($encrypted),
                't' => base64_encode($tag),
                'e' => time() + $expiry, // Expiration timestamp
            );

            // Sign the payload
            $payload_string = wp_json_encode($payload);
            $signature = hash_hmac('sha256', $payload_string, $keys['signing_key']);

            // Final cookie value
            $cookie_value = base64_encode($payload_string . '.' . $signature);

            // Set cookie options
            $cookie_options = wp_parse_args($options, array(
                'expires'  => time() + $expiry,
                'path'     => '/',
                'domain'   => $this->get_cookie_domain(),
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));

            // Set the cookie
            return setcookie(
                self::COOKIE_PREFIX . $name,
                $cookie_value,
                $cookie_options
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get and decrypt a secure cookie
     *
     * @param string $name Cookie name (without prefix).
     * @param bool   $json_decode Whether to JSON decode the result.
     * @return mixed Decrypted value or null on failure.
     */
    public function get_secure_cookie(string $name, bool $json_decode = true)
    {
        $cookie_name = self::COOKIE_PREFIX . $name;

        if (! isset($_COOKIE[$cookie_name])) {
            return null;
        }

        try {
            // Get cookie value and sanitize by allowing only base64 characters
            // Using preg_replace as sanitization (whitelist-based) to preserve encrypted data integrity
            // Note: We already checked isset() above, so cookie exists at this point
            $raw_cookie = wp_unslash($_COOKIE[$cookie_name]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on next line with whitelist regex

            // Sanitize: strip any characters that aren't valid base64 (whitelist approach)
            $sanitized_cookie = preg_replace('/[^a-zA-Z0-9\/+=\r\n]/', '', $raw_cookie);

            if (empty($sanitized_cookie)) {
                return null;
            }

            // Decode the cookie value
            $cookie_value = base64_decode($sanitized_cookie, true);
            if (false === $cookie_value) {
                return null;
            }

            // Split payload and signature
            $parts = explode('.', $cookie_value, 2);
            if (2 !== count($parts)) {
                return null;
            }

            list($payload_string, $signature) = $parts;

            // Get keys
            $keys = $this->get_encryption_keys();
            if (! $keys) {
                return null;
            }

            // Verify signature
            $expected_signature = hash_hmac('sha256', $payload_string, $keys['signing_key']);
            if (! hash_equals($expected_signature, $signature)) {
                return null;
            }

            // Decode payload
            $payload = json_decode($payload_string, true);
            if (! is_array($payload) || ! isset($payload['v'], $payload['i'], $payload['d'], $payload['t'], $payload['e'])) {
                return null;
            }

            // Check expiration
            if ($payload['e'] < time()) {
                return null;
            }

            // Decode components
            $iv = base64_decode($payload['i'], true);
            $encrypted = base64_decode($payload['d'], true);
            $tag = base64_decode($payload['t'], true);

            if (false === $iv || false === $encrypted || false === $tag) {
                return null;
            }

            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER_METHOD,
                $keys['encryption_key'],
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if (false === $decrypted) {
                return null;
            }

            // Optionally JSON decode
            if ($json_decode) {
                $decoded = json_decode($decrypted, true);
                return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $decrypted;
            }

            return $decrypted;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Delete a secure cookie
     *
     * @param string $name Cookie name (without prefix).
     * @return bool
     */
    public function delete_secure_cookie(string $name)
    {
        $cookie_name = self::COOKIE_PREFIX . $name;

        // Delete the cookie - use the same domain as when setting the cookie
        $result = setcookie(
            $cookie_name,
            '',
            time() - 3600,
            '/',
            $this->get_cookie_domain(),
            is_ssl(),
            true
        );

        // Remove from superglobal
        unset($_COOKIE[$cookie_name]);

        return $result;
    }

    /**
     * Store A/B test data securely
     *
     * @param array $data Test data including session_id and assignments.
     * @param int   $expiry Expiration in seconds.
     * @return bool
     */
    public function store_ab_test_data(array $data, int $expiry = self::DEFAULT_EXPIRY)
    {
        return $this->set_secure_cookie('ab_data', $data, $expiry);
    }

    /**
     * Get A/B test data
     *
     * @return array|null Test data or null if not found.
     */
    public function get_ab_test_data()
    {

        return $this->get_secure_cookie('ab_data');
    }

    /**
     * Get encryption keys
     *
     * @return array|false Array with 'encryption_key' and 'signing_key' or false on failure.
     */
    private function get_encryption_keys()
    {
        // Check cache first
        if (null !== $this->keys_cache) {
            return $this->keys_cache;
        }

        // Try to get from object cache
        $keys = wp_cache_get('encryption_keys', self::CACHE_GROUP);
        if (false !== $keys && is_array($keys)) {
            $this->keys_cache = $keys;
            return $keys;
        }

        // Get from database
        $stored_keys = get_option(self::KEY_OPTION);

        if (false === $stored_keys || ! is_array($stored_keys)) {
            // Generate new keys
            $keys = $this->generate_keys();
            if (false === $keys) {
                return false;
            }

            // Store encrypted in database
            if (! $this->store_keys($keys)) {
                return false;
            }
        } else {
            // Decrypt stored keys
            $keys = $this->decrypt_stored_keys($stored_keys);
            if (false === $keys) {
                return false;
            }
        }

        // Cache the keys
        wp_cache_set('encryption_keys', $keys, self::CACHE_GROUP, 3600);
        $this->keys_cache = $keys;

        return $keys;
    }

    /**
     * Generate new encryption keys
     *
     * @return array|false
     */
    private function generate_keys()
    {
        try {
            // Generate random keys
            $encryption_key = openssl_random_pseudo_bytes(32);
            $signing_key = openssl_random_pseudo_bytes(32);

            if (false === $encryption_key || false === $signing_key) {
                return false;
            }

            return array(
                'encryption_key' => $encryption_key,
                'signing_key' => $signing_key,
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Store keys securely in database
     *
     * @param array $keys Keys to store.
     * @return bool
     */
    private function store_keys(array $keys)
    {
        try {
            // Use WordPress salts for key derivation
            $master_key = $this->derive_master_key();

            // Encrypt keys before storage
            $iv = openssl_random_pseudo_bytes(16);
            $tag = '';

            $encrypted_keys = openssl_encrypt(
                serialize($keys),
                self::CIPHER_METHOD,
                $master_key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if (false === $encrypted_keys) {
                return false;
            }

            $stored_data = array(
                'iv' => base64_encode($iv),
                'data' => base64_encode($encrypted_keys),
                'tag' => base64_encode($tag),
                'created' => time(),
            );

            return update_option(self::KEY_OPTION, $stored_data, false);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Decrypt stored keys
     *
     * @param array $stored_data Stored encrypted data.
     * @return array|false
     */
    private function decrypt_stored_keys(array $stored_data)
    {
        try {
            if (! isset($stored_data['iv'], $stored_data['data'], $stored_data['tag'])) {
                return false;
            }

            $master_key = $this->derive_master_key();

            $iv = base64_decode($stored_data['iv'], true);
            $encrypted = base64_decode($stored_data['data'], true);
            $tag = base64_decode($stored_data['tag'], true);

            if (false === $iv || false === $encrypted || false === $tag) {
                return false;
            }

            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER_METHOD,
                $master_key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if (false === $decrypted) {
                return false;
            }

            $keys = unserialize($decrypted);
            if (! is_array($keys) || ! isset($keys['encryption_key'], $keys['signing_key'])) {
                return false;
            }

            return $keys;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Derive master key from WordPress salts
     *
     * @return string
     */
    private function derive_master_key()
    {
        // Combine WordPress salts for key derivation
        $salt_data = '';
        $salts = array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY');

        foreach ($salts as $salt) {
            if (defined($salt)) {
                $salt_data .= constant($salt);
            }
        }

        // Fallback if salts are not defined (should not happen in production)
        if (empty($salt_data)) {
            $salt_data = wp_salt('auth') . wp_salt('secure_auth');
        }

        // Derive key using PBKDF2
        return hash_pbkdf2('sha256', $salt_data, 'sigmize_cookie_encryption', 10000, 32, true);
    }

    /**
     * Set an HMAC-signed GDPR consent cookie
     *
     * Builds a signed token using the existing signing key — no AES encryption
     * needed since consent status is not sensitive, only integrity matters.
     * Cookie format: {base64_json_payload}.{hmac_sha256_hex}
     *
     * @since 0.0.11
     *
     * @param int $expiry Expiration time in seconds from now. Default 1 year.
     * @return string|false Cookie value string on success, false on failure.
     */
    public function set_gdpr_consent_cookie(int $expiry = 31536000)
    {
        try {
            $keys = $this->get_encryption_keys();
            if (! $keys) {
                return false;
            }

            $payload = wp_json_encode(array(
                'c' => 'accepted',
                't' => time(),
            ));

            if (false === $payload) {
                return false;
            }

            $b64_payload = base64_encode($payload);
            $hmac        = hash_hmac('sha256', $b64_payload, $keys['signing_key']);
            $cookie_value = $b64_payload . '.' . $hmac;

            $result = setcookie(
                'sigmize_gdpr_consent',
                $cookie_value,
                array(
                    'expires'  => time() + $expiry,
                    'path'     => '/',
                    'domain'   => $this->get_cookie_domain(),
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                )
            );

            return $result ? $cookie_value : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verify the HMAC-signed GDPR consent cookie
     *
     * Splits the cookie on '.', recomputes the HMAC with hash_equals() for
     * timing-safe comparison, then validates payload fields and timestamp.
     *
     * @since 0.0.11
     *
     * @param int $expiry Maximum accepted age of the consent token in seconds. Default 1 year.
     * @return bool True only when all checks pass.
     */
    public function verify_gdpr_consent_cookie(int $expiry = 31536000): bool
    {
        if (! isset($_COOKIE['sigmize_gdpr_consent'])) {
            return false;
        }

        try {
            $keys = $this->get_encryption_keys();
            if (! $keys) {
                return false;
            }

            // Sanitize: allow only base64url chars, dots, and hex chars
            $raw = wp_unslash($_COOKIE['sigmize_gdpr_consent']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on next line
            $sanitized = preg_replace('/[^a-zA-Z0-9\/+=.\-]/', '', $raw);

            $parts = explode('.', $sanitized, 2);
            if (2 !== count($parts)) {
                return false;
            }

            list($b64_payload, $provided_hmac) = $parts;

            $expected_hmac = hash_hmac('sha256', $b64_payload, $keys['signing_key']);
            if (! hash_equals($expected_hmac, $provided_hmac)) {
                return false;
            }

            $payload_json = base64_decode($b64_payload, true);
            if (false === $payload_json) {
                return false;
            }

            $payload = json_decode($payload_json, true);
            if (! is_array($payload) || ! isset($payload['c'], $payload['t'])) {
                return false;
            }

            if ($payload['c'] !== 'accepted') {
                return false;
            }

            if ((time() - (int) $payload['t']) > $expiry) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete the GDPR consent cookie.
     *
     * Expires the HMAC-signed GDPR consent cookie using matching
     * domain/path parameters so the browser removes it on the next request.
     *
     * @since 0.0.11
     *
     * @return bool True on success.
     */
    public function delete_gdpr_consent_cookie(): bool
    {
        $result = setcookie(
            'sigmize_gdpr_consent',
            '',
            array(
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => $this->get_cookie_domain(),
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            )
        );

        unset($_COOKIE['sigmize_gdpr_consent']);

        return $result;
    }

    /**
     * Rotate encryption keys
     *
     * @return bool
     */
    public function rotate_keys()
    {
        // Clear caches
        wp_cache_delete('encryption_keys', self::CACHE_GROUP);
        $this->keys_cache = null;

        // Generate new keys
        $new_keys = $this->generate_keys();
        if (false === $new_keys) {
            return false;
        }

        // Store new keys
        return $this->store_keys($new_keys);
    }
}
