<?php

namespace App\Services;

use RuntimeException;

class BackupCrypto
{
    private const MAGIC = 'PDBK1';

    private const SALT_BYTES = 16;

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    private const PBKDF2_ITERATIONS = 210000;

    public function encrypt(string $plaintext): string
    {
        $passphrase = $this->passphrase();
        $salt = random_bytes(self::SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->deriveKey($passphrase, $salt),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('备份加密失败');
        }

        return self::MAGIC.$salt.$iv.$tag.$ciphertext;
    }

    public function decrypt(string $payload): string
    {
        $offset = strlen(self::MAGIC);

        if (substr($payload, 0, $offset) !== self::MAGIC) {
            throw new RuntimeException('备份文件格式不正确');
        }

        $salt = substr($payload, $offset, self::SALT_BYTES);
        $offset += self::SALT_BYTES;
        $iv = substr($payload, $offset, self::IV_BYTES);
        $offset += self::IV_BYTES;
        $tag = substr($payload, $offset, self::TAG_BYTES);
        $offset += self::TAG_BYTES;
        $ciphertext = substr($payload, $offset);

        if (strlen($salt) !== self::SALT_BYTES
            || strlen($iv) !== self::IV_BYTES
            || strlen($tag) !== self::TAG_BYTES
            || $ciphertext === '') {
            throw new RuntimeException('备份文件已损坏');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->deriveKey($this->passphrase(), $salt),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new RuntimeException('备份解密失败：口令错误或文件被篡改');
        }

        return $plaintext;
    }

    private function passphrase(): string
    {
        $passphrase = config('backup.passphrase');

        if (! is_string($passphrase) || mb_strlen($passphrase) < 16) {
            throw new RuntimeException('请先设置至少 16 字符的 BACKUP_PASSPHRASE');
        }

        return $passphrase;
    }

    private function deriveKey(string $passphrase, string $salt): string
    {
        return hash_pbkdf2(
            'sha256',
            $passphrase,
            $salt,
            self::PBKDF2_ITERATIONS,
            32,
            true,
        );
    }
}
