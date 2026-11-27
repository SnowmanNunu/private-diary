<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

class TwoFactorAuth
{
    public function generateSecret(): string
    {
        return (new Google2FA())->generateSecretKey(32);
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $label = rawurlencode(config('app.name').':'.$user->email);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => config('app.name'),
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    public function qrCodeSvg(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220),
            new SvgImageBackEnd(),
        );

        return (new Writer($renderer))->writeString($uri);
    }

    public function verifySecret(string $secret, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            $google2fa = new Google2FA();
            $google2fa->setWindow(1);

            return $google2fa->verifyKey($secret, $code);
        } catch (Throwable) {
            return false;
        }
    }

    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled() || ! is_string($user->two_factor_secret)) {
            return false;
        }

        return $this->verifySecret($user->two_factor_secret, $code);
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        while (count($codes) < $count) {
            $code = strtoupper(bin2hex(random_bytes(6)));

            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code): string => $this->hashRecoveryCode($code), $codes);
    }

    /**
     * @return array<int, string>
     */
    public function enable(User $user, string $secret): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = $this->hashRecoveryCodes($codes);
        $user->two_factor_enabled_at = now();
        $user->save();

        return $codes;
    }

    /**
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->two_factor_recovery_codes = $this->hashRecoveryCodes($codes);
        $user->save();

        return $codes;
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        $hashes = $user->two_factor_recovery_codes ?? [];

        if (strlen($normalized) !== 12 || $hashes === []) {
            return false;
        }

        foreach ($hashes as $index => $hash) {
            if (! is_string($hash) || ! hash_equals($hash, $this->hashRecoveryCode($normalized))) {
                continue;
            }

            unset($hashes[$index]);
            $user->two_factor_recovery_codes = array_values($hashes);
            $user->save();

            return true;
        }

        return false;
    }

    public function disable(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_enabled_at = null;
        $user->save();
    }

    public static function formatRecoveryCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->appKey());
    }

    private function appKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7), true) ?: $key;
        }

        return $key;
    }
}
