<?php

declare(strict_types=1);

namespace App\Services\Siat;

final class SiatLogSanitizer
{
    private const REDACTED = '[REDACTADO]';

    /** @var array<int, string> */
    private const SENSITIVE_KEYS = [
        'apitoken', 'token', 'apikey', 'authorization', 'password',
        'contrasena', 'systemcode', 'codigosistema', 'privatekey', 'certificate',
    ];

    public function text(?string $value, ?string $knownToken = null): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($knownToken !== null && $knownToken !== '') {
            $value = str_replace($knownToken, self::REDACTED, $value);
        }

        $value = preg_replace(
            '/\bauthorization\b\s*[:=]\s*(?:bearer\s+)?[^\s,;"\']+/iu',
            'authorization='.self::REDACTED,
            $value,
        ) ?? $value;

        return preg_replace(
            '/\b(api[_-]?token|token|authorization|apikey|api-key|password|contrasena)\b\s*[:=]\s*["\']?[^\s,;"\']+/iu',
            '$1='.self::REDACTED,
            $value,
        ) ?? $value;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public function data(?array $data, ?string $knownToken = null): ?array
    {
        if ($data === null) {
            return null;
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->data($value, $knownToken);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->text($value, $knownToken);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', mb_strtolower($key)) ?? '';

        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }
}
