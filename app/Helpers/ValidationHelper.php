<?php
/**
 * OzyConnect Panel — Validation Helper
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Helpers;

final class ValidationHelper
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * Kuralları uygula
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $rules  Alan adı => kural dizesi (ör: "required|email|max:200")
     * @return bool Geçerli mi
     */
    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            $label = $this->fieldLabel($field);

            foreach ($fieldRules as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $error = $this->checkRule($rule, $value, $params, $label, $field, $data);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break; // İlk hatada dur, sonrakini kontrol etme
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * Hataları getir
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * İlk hatayı getir
     */
    public function firstError(): ?string
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    /**
     * Tek kural kontrol
     *
     * @param array<int, string> $params
     * @param array<string, mixed> $data Tüm form verisi (confirmed kuralı eşleştirme için gerekli)
     */
    private function checkRule(string $rule, mixed $value, array $params, string $label, string $field, array $data): ?string
    {
        return match ($rule) {
            'required'  => $this->ruleRequired($value, $label),
            'email'     => $this->ruleEmail($value, $label),
            'min'       => $this->ruleMin($value, (int) ($params[0] ?? 0), $label),
            'max'       => $this->ruleMax($value, (int) ($params[0] ?? 0), $label),
            'numeric'   => $this->ruleNumeric($value, $label),
            'integer'   => $this->ruleInteger($value, $label),
            'in'        => $this->ruleIn($value, $params, $label),
            'url'       => $this->ruleUrl($value, $label),
            'confirmed' => $this->ruleConfirmed($value, $field, $data, $label),
            default     => null,
        };
    }

    /**
     * "confirmed" — {$field}_confirmation alanı $field ile bire-bir eşleşmeli.
     *
     * @param array<string, mixed> $data
     */
    private function ruleConfirmed(mixed $value, string $field, array $data, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $other = $data[$field . '_confirmation'] ?? null;
        if ($value !== $other) {
            return "{$label} alanı doğrulama ile eşleşmiyor.";
        }
        return null;
    }

    private function ruleRequired(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return "{$label} alanı zorunludur.";
        }
        return null;
    }

    private function ruleEmail(mixed $value, string $label): ?string
    {
        if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return "{$label} geçerli bir e-posta adresi olmalıdır.";
        }
        return null;
    }

    private function ruleMin(mixed $value, int $min, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            if (mb_strlen($value, 'UTF-8') < $min) {
                return "{$label} en az {$min} karakter olmalıdır.";
            }
            return null;
        }

        if (is_numeric($value) && (float) $value < $min) {
            return "{$label} en az {$min} olmalıdır.";
        }

        return null;
    }

    private function ruleMax(mixed $value, int $max, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            if (mb_strlen($value, 'UTF-8') > $max) {
                return "{$label} en fazla {$max} karakter olmalıdır.";
            }
            return null;
        }

        if (is_numeric($value) && (float) $value > $max) {
            return "{$label} en fazla {$max} olmalıdır.";
        }

        return null;
    }

    private function ruleNumeric(mixed $value, string $label): ?string
    {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            return "{$label} sayısal bir değer olmalıdır.";
        }
        return null;
    }

    private function ruleInteger(mixed $value, string $label): ?string
    {
        if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            return "{$label} tam sayı olmalıdır.";
        }
        return null;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function ruleIn(mixed $value, array $allowed, string $label): ?string
    {
        if ($value !== null && $value !== '' && !in_array((string) $value, $allowed, true)) {
            return "{$label} geçersiz bir değer içermektedir.";
        }
        return null;
    }

    private function ruleUrl(mixed $value, string $label): ?string
    {
        if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            return "{$label} geçerli bir URL olmalıdır.";
        }
        return null;
    }

    /**
     * Alan adından Türkçe etiket türet
     */
    private function fieldLabel(string $field): string
    {
        $labels = [
            'name'     => 'Ad',
            'email'    => 'E-posta',
            'password' => 'Şifre',
            'sku'      => 'SKU',
            'barcode'  => 'Barkod',
            'price'    => 'Fiyat',
            'stock'    => 'Stok',
        ];

        return $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }
}
