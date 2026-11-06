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
        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $months = self::MONTHS[$lang] ?? self::MONTHS['en'];
        $month = $months[(int) $date->format('n') - 1];

        return $lang === 'de'
            ? $date->format('j') . '. ' . $month . ' ' . $date->format('Y')
            : $date->format('j') . ' ' . $month . ' ' . $date->format('Y');
    }

    public static function iso(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format(\DateTimeInterface::ATOM);
    }
}
