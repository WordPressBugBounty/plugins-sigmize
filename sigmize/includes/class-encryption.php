<?php

/**
 * Based on the code from the following packages:
 * Class Google\Site_Kit\Core\Storage\Data_Encryption
 *
 * @package   Google\Site_Kit
 * @copyright 2019 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Sigmize;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class responsible for encrypting and decrypting data.
 *
 * @since 0.0.1
 * @access private
 * @ignore
 */
class Encryption
{
    /**
     * Key to use for encryption.
     *
     * @since 0.0.1
     * @var string
     */
    private $key;

    /**
     * Salt to use for encryption.
     *
     * @since 0.0.1
     * @var string
     */
    private $salt;

    /**
     * Constructor.
     *
     * @since 0.0.1
     */
    final public function __construct()
    {
        $this->key  = $this->get_default_key();
        $this->salt = $this->get_default_salt();
    }

    /**
     * Encrypts a value.
     *
     * If a user-based key is set, that key is used. Otherwise the default key is used.
     *
     * @since 0.0.1
     *
     * @param string $value Value to encrypt.
     * @return string|bool Encrypted value, or false on failure.
     */
    public function encrypt($value)
    {
        if (! extension_loaded('openssl')) {
            return $value;
        }
        $method = 'aes-256-ctr';
        $ivlen  = openssl_cipher_iv_length($method);
        $iv     = openssl_random_pseudo_bytes($ivlen);

        $raw_value = openssl_encrypt($value . $this->salt, $method, $this->key, 0, $iv);
        if (! $raw_value) {
            return false;
        }

        return base64_encode($iv . $raw_value); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Decrypts a value.
     *
     * If a user-based key is set, that key is used. Otherwise the default key is used.
     *
     * @since 0.0.1
     *
     * @param string $raw_value Value to decrypt.
     * @return string|bool Decrypted value, or false on failure.
     */
    public function decrypt($raw_value)
    {
        if (! extension_loaded('openssl')) {
            return $raw_value;
        }

        $raw_value = base64_decode($raw_value, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        $method = 'aes-256-ctr';
        $ivlen  = openssl_cipher_iv_length($method);
        $iv     = substr($raw_value, 0, $ivlen);

        $raw_value = substr($raw_value, $ivlen);

        $value = openssl_decrypt($raw_value, $method, $this->key, 0, $iv);
        if (! $value || substr($value, -strlen($this->salt)) !== $this->salt) {
            return false;
        }
        return substr($value, 0, -strlen($this->salt));
    }

    /**
     * Gets the default encryption key to use.
     *
     * @since 0.0.1
     *
     * @return string Default (not user-based) encryption key.
     */
    protected function get_default_key()
    {

        if (defined('SIGMIZE_ENCRYPTION_KEY') && '' !== SIGMIZE_ENCRYPTION_KEY) {
            return SIGMIZE_ENCRYPTION_KEY;
        }

        if (defined('LOGGED_IN_KEY') && '' !== LOGGED_IN_KEY) {
            return LOGGED_IN_KEY;
        }

        // If this is reached, you're either not on a live site or have a serious security issue.
        return 'this-is-fallback-key-for-encryption';
    }

    /**
     * Gets the default encryption salt to use.
     *
     * @since 0.0.1
     *
     * @return string Encryption salt.
     */
    private function get_default_salt()
    {
        if (defined('SIGMIZE_ENCRYPTION_SALT') && '' !== SIGMIZE_ENCRYPTION_SALT) {
            return SIGMIZE_ENCRYPTION_SALT;
        }

        if (defined('LOGGED_IN_SALT') && '' !== LOGGED_IN_SALT) {
            return LOGGED_IN_SALT;
        }

        // If this is reached, you're either not on a live site or have a serious security issue.
        return 'this-is-fallback-salt-for-encryption';
    }

}
