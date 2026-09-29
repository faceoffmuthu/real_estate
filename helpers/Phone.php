<?php
declare(strict_types=1);

/**
 * Phone numbers are stored in E.164 form (e.g. +919876543210) so they can be
 * used directly for tel: links and WhatsApp (wa.me/919876543210).
 */
final class Phone
{
    private static ?string $defaultCountryCode = null;

    /** Returns the normalised number, or null when the input cannot be a valid phone number. */
    public static function normalize(mixed $input): ?string
    {
        if (!is_string($input) && !is_int($input)) {
            return null;
        }
        $raw = trim((string) $input);
        if ($raw === '' || !preg_match('/^\+?[0-9\s\-().]{7,25}$/', $raw)) {
            return null;
        }
        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw);

        if ($hasPlus) {
            $e164 = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = substr($digits, 2);                             // 00 international prefix
        } elseif (strlen($digits) === 10) {
            $e164 = self::countryCode() . $digits;                  // local mobile number
        } elseif (strlen($digits) === 11 && $digits[0] === '0') {
            $e164 = self::countryCode() . substr($digits, 1);       // trunk prefix 0
        } else {
            $e164 = $digits;                                        // assume country code included
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $e164) ? '+' . $e164 : null;
    }

    /** Digits only — used for partial phone search. */
    public static function digits(string $input): string
    {
        return preg_replace('/\D/', '', $input);
    }

    private static function countryCode(): string
    {
        if (self::$defaultCountryCode === null) {
            $setting = (string) Database::value("SELECT setting_value FROM settings WHERE setting_key = 'default_country_code'");
            self::$defaultCountryCode = self::digits($setting) ?: '91';
        }
        return self::$defaultCountryCode;
    }
}
