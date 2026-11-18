<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Config
{
    private const CIPHER = 'aes-256-cbc';
    private const PREFIX = 'enc:';

    /** @var array<string, mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    public static function load(array $config): void
    {
        self::$items = array_replace_recursive(self::$items, $config);
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded && file_exists(__DIR__ . '/../config.php')) {
            self::load(require __DIR__ . '/../config.php');
        }

        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        if (is_string($value) && str_starts_with($value, self::PREFIX)) {
            return self::decrypt($value);
        }

        return $value;
    }

    public static function set(string $key, mixed $value, bool $encrypt = false): void
    {
        if ($encrypt && is_string($value)) {
            $value = self::encrypt($value);
        }

        $segments = explode('.', $key);
        $target = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;
    }

    public static function encrypt(string $plainText): string
    {
        $key = self::getMasterKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);

        $cipherText = openssl_encrypt($plainText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            throw new RuntimeException('Ошибка шифрования значения конфига.');
        }

        return self::PREFIX . base64_encode($iv . $cipherText);
    }

    public static function decrypt(string $encryptedValue): string
    {
        if (!str_starts_with($encryptedValue, self::PREFIX)) {
            return $encryptedValue;
        }

        $raw = base64_decode(substr($encryptedValue, strlen(self::PREFIX)), true);
        if ($raw === false) {
            throw new RuntimeException('Некорректный base64 в зашифрованном значении конфига.');
        }

        $key = self::getMasterKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $cipherText = substr($raw, $ivLength);

        $plainText = openssl_decrypt($cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($plainText === false) {
            throw new RuntimeException('Не удалось расшифровать значение конфига (проверьте APP_KEY).');
        }

        return $plainText;
    }

    private static function getMasterKey(): string
    {
        $secret = getenv('APP_KEY') ?: 'default-insecure-dev-key-change-me';
        return hash('sha256', $secret, true);
    }
}
