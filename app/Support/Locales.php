<?php

namespace App\Support;

/** Supported interface languages; adding one is a catalog plus an entry in config/app.php. */
final class Locales
{
    public const DEFAULT = 'sq';

    /** @return list<string> */
    public static function all(): array
    {
        return config('app.supported_locales', [self::DEFAULT]);
    }

    public static function supports(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::all(), true);
    }

    public static function resolve(mixed $locale): string
    {
        return self::supports($locale) ? $locale : self::DEFAULT;
    }

    /** English uses d/m/Y; Albanian and Macedonian write dates with dots. */
    public static function dateFormat(?string $locale = null, bool $withTime = false): string
    {
        $format = ($locale ?? app()->getLocale()) === 'en' ? 'd/m/Y' : 'd.m.Y';

        return $withTime ? $format.' H:i' : $format;
    }
}
