<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Redaction;

/**
 * The default for Policy::redact(): replaces email addresses, phone numbers
 * and long digit runs (card, account and order numbers) in every string,
 * however deeply nested. A floor, not a guarantee: names, addresses and
 * anything domain-specific need the policy's own redact().
 */
final readonly class Redactor
{
    public const string EMAIL = '[email]';

    public const string PHONE = '[phone]';

    public const string NUMBER = '[number]';

    /**
     * Shorter runs are left alone, so dates, times, prices and sort codes
     * survive. An unbroken run needs fewer digits than one with separators,
     * so an 8-digit account number goes but 2026-11-13 stays.
     */
    public const int MIN_DIGITS = 8;

    public const int MIN_SEPARATED_DIGITS = 9;

    private const string EMAIL_PATTERN = '/[\p{L}\p{N}._%+\'-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,}/u';

    /** A digit, then digits and separators, then a digit; also inside a reference like ABC12345678. */
    private const string DIGITS_PATTERN = '/(?<!\d)\+?\(?\d[\d\s().\/-]*\d(?!\d)/u';

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    public function redact(array $input): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            is_string($value) => $this->redactString($value),
            is_array($value) => $this->redact($value),
            default => $value,
        }, $input);
    }

    public function redactString(string $value): string
    {
        $value = (string) preg_replace(self::EMAIL_PATTERN, self::EMAIL, $value);

        return (string) preg_replace_callback(self::DIGITS_PATTERN, static function (array $match): string {
            $digits = strlen((string) preg_replace('/\D/', '', $match[0]));
            $unbroken = ctype_digit($match[0]);

            if ($digits < ($unbroken ? self::MIN_DIGITS : self::MIN_SEPARATED_DIGITS)) {
                return $match[0];
            }

            return self::isPhone($match[0], $digits, $unbroken) ? self::PHONE : self::NUMBER;
        }, $value);
    }

    /**
     * +, ( or a trunk 0 up to 15 digits (E.164), or 10 to 11 digits split
     * like 555-123-4567. Longer split runs are cards and accounts.
     */
    private static function isPhone(string $match, int $digits, bool $unbroken): bool
    {
        if (preg_match('/^(?:\+|\(|0)/', $match) === 1) {
            return $digits <= 15;
        }

        return ! $unbroken && $digits <= 11;
    }
}
