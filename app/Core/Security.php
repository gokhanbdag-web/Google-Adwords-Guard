<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Security
{
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function encrypt(string $plainText): string
    {
        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cipherText === false) {
            throw new RuntimeException('Şifreleme başarısız.');
        }

        return base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Şifreli veri geçersiz.');
        }

        $plainText = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );

        if ($plainText === false) {
            throw new RuntimeException('Şifre çözme başarısız.');
        }

        return $plainText;
    }

    private static function encryptionKey(): string
    {
        $appKey = (string) env('APP_KEY', '');

        if (strlen($appKey) < 32) {
            throw new RuntimeException('APP_KEY en az 32 karakter olmalıdır.');
        }

        return hash('sha256', $appKey, true);
    }
}