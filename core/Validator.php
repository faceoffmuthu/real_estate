<?php
declare(strict_types=1);

/**
 * Chainable input validator. Only the first error per field is kept.
 * Rules other than required() are skipped when the field is empty.
 *
 *   $v = (new Validator($data))->required('email', 'Email')->email('email');
 *   if ($v->fails()) Response::validation($v->errors());
 */
final class Validator
{
    public const PASSWORD_RULE = 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter and a number.';

    private array $errors = [];

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function required(string $field, string $label): self
    {
        if ($this->isEmpty($field)) {
            $this->add($field, "$label is required.");
        }
        return $this;
    }

    public function string(string $field, string $label, int $min = 0, int $max = 255): self
    {
        if ($this->skip($field)) {
            return $this;
        }
        $value = $this->data[$field];
        if (!is_string($value)) {
            return $this->add($field, "$label must be text.");
        }
        $length = mb_strlen(trim($value));
        if ($length < $min) {
            return $this->add($field, "$label must be at least $min characters.");
        }
        if ($length > $max) {
            return $this->add($field, "$label must not exceed $max characters.");
        }
        return $this;
    }

    public function email(string $field, string $label = 'Email'): self
    {
        if (!$this->skip($field) && (!is_string($this->data[$field]) || filter_var(trim($this->data[$field]), FILTER_VALIDATE_EMAIL) === false)) {
            $this->add($field, "$label must be a valid email address.");
        }
        return $this;
    }

    public function username(string $field): self
    {
        if (!$this->skip($field) && (!is_string($this->data[$field]) || !preg_match('/^[a-zA-Z0-9._]{3,50}$/', trim($this->data[$field])))) {
            $this->add($field, 'Username must be 3-50 characters: letters, numbers, dots or underscores.');
        }
        return $this;
    }

    public function phone(string $field): self
    {
        if (!$this->skip($field) && (!is_string($this->data[$field]) || !preg_match('/^\+?[0-9\s\-()]{7,20}$/', trim($this->data[$field])))) {
            $this->add($field, 'Phone must be 7-20 digits and may include +, spaces, dashes or brackets.');
        }
        return $this;
    }

    public function password(string $field): self
    {
        if ($this->skip($field)) {
            return $this;
        }
        $value = $this->data[$field];
        if (!is_string($value) || strlen($value) < 8 || strlen($value) > 72
            || !preg_match('/[A-Z]/', $value) || !preg_match('/[a-z]/', $value) || !preg_match('/[0-9]/', $value)) {
            $this->add($field, self::PASSWORD_RULE);
        }
        return $this;
    }

    public function in(string $field, string $label, array $allowed): self
    {
        if (!$this->skip($field) && !in_array($this->data[$field], $allowed, true)) {
            $this->add($field, "$label must be one of: " . implode(', ', $allowed) . '.');
        }
        return $this;
    }

    public function integer(string $field, string $label): self
    {
        if (!$this->skip($field) && filter_var($this->data[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->add($field, "$label must be a valid ID.");
        }
        return $this;
    }

    /**
     * Parses plain decimals plus amounts grouped with Indian or standard
     * separators. A trailing group of one or two digits is treated as the
     * decimal part; a three-digit group is a grouping separator.
     */
    public static function normaliseNumber(mixed $value): int|float|string|null
    {
        if (is_int($value) || is_float($value)) return $value;
        if (!is_string($value)) return null;
        $raw = preg_replace('/\s+/', '', trim($value));
        if (!is_string($raw) || $raw === '' || !preg_match('/^\d+(?:[.,]\d+)*$/', $raw)) return null;

        $dot = strrpos($raw, '.');
        $comma = strrpos($raw, ',');
        $last = max($dot === false ? -1 : $dot, $comma === false ? -1 : $comma);
        $fraction = $last < 0 ? '' : substr($raw, $last + 1);
        $hasDecimal = $last >= 0 && strlen($fraction) <= 2;
        $integer = preg_replace('/[.,]/', '', $hasDecimal ? substr($raw, 0, $last) : $raw);
        if (!is_string($integer) || $integer === '') return null;

        $normalised = $hasDecimal ? "{$integer}.{$fraction}" : $integer;
        return is_numeric($normalised) ? $normalised : null;
    }

    /** Numeric value (int/float or numeric string) within [min, max]. */
    public function number(string $field, string $label, float $min = 0, float $max = PHP_FLOAT_MAX): self
    {
        if ($this->skip($field)) {
            return $this;
        }
        $number = self::normaliseNumber($this->data[$field]);
        if ($number === null) {
            return $this->add($field, "$label must be a number.");
        }
        if ((float) $number < $min || (float) $number > $max) {
            $fmt = static fn (float $n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
            return $this->add($field, "$label must be between {$fmt($min)} and {$fmt($max)}.");
        }
        return $this;
    }

    /** Calendar date in Y-m-d format. */
    public function date(string $field, string $label): self
    {
        if ($this->skip($field)) {
            return $this;
        }
        $value = $this->data[$field];
        $parsed = is_string($value) ? DateTime::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $this->add($field, "$label must be a valid date (YYYY-MM-DD).");
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $message): self
    {
        if (!$this->skip($field) && (!is_string($this->data[$field]) || !preg_match($pattern, trim($this->data[$field])))) {
            $this->add($field, $message);
        }
        return $this;
    }

    public function add(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function isEmpty(string $field): bool
    {
        $value = $this->data[$field] ?? null;
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function skip(string $field): bool
    {
        return isset($this->errors[$field]) || $this->isEmpty($field);
    }
}
