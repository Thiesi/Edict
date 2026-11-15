<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * Dates in the article's own language. A table rather than ext-intl, for two
 * languages and without another extension on the host.
 */
final class ArticleDate
{
    private const TIMEZONE = 'Europe/Berlin';

    private const MONTHS = [
        'de' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    public static function format(int $timestamp, string $lang): string
    {
        $date = self::local($timestamp);
        $months = self::MONTHS[$lang] ?? self::MONTHS['en'];
        $month = $months[(int) $date->format('n') - 1];

        return $lang === 'de'
            ? $date->format('j') . '. ' . $month . ' ' . $date->format('Y')
            : $date->format('j') . ' ' . $month . ' ' . $date->format('Y');
    }

    public static function iso(int $timestamp): string
    {
        return self::local($timestamp)->format(\DateTimeInterface::ATOM);
    }

    /**
     * Date and time for the admin, in the site's time: "15 Nov 2026, 18:00".
     */
    public static function adminDateTime(int $timestamp): string
    {
        return self::local($timestamp)->format('j M Y, H:i');
    }

    /**
     * The calendar day of this moment in the site's time, as a SQL date: "2026-11-15".
     */
    public static function day(int $timestamp): string
    {
        return self::local($timestamp)->format('Y-m-d');
    }

    /**
     * The value of a datetime-local field showing this moment in the site's time.
     */
    public static function fieldValue(int $timestamp): string
    {
        return self::local($timestamp)->format('Y-m-d\TH:i');
    }

    /**
     * A datetime-local field's value, read as the site's time.
     *
     * @return int|null null for anything that isn't a real minute on the site's clock:
     *                  malformed, a day that doesn't exist, or the hour skipped when the clocks go forward
     */
    public static function fromFieldValue(string $value): ?int
    {
        // Some browsers add seconds. Scheduling is to the minute.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,3})?)?$/D', $value) !== 1) {
            return null;
        }
        $value = substr($value, 0, 16);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone(self::TIMEZONE));

        // PHP rolls 30 February into March and 02:30 on the spring-forward night into 03:30.
        return $date === false || $date->format('Y-m-d\TH:i') !== $value ? null : $date->getTimestamp();
    }

    private static function local(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }
}
