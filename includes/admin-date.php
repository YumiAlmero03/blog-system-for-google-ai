<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function admin_display_timezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    try {
        $timezone = new DateTimeZone(env_value('ADMIN_TIMEZONE') ?: 'Asia/Manila');
    } catch (Exception) {
        $timezone = new DateTimeZone('Asia/Manila');
    }
    return $timezone;
}

function admin_format_date(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    try {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            $date = (new DateTimeImmutable('@' . (string) $value))->setTimezone(admin_display_timezone());
        } else {
            $date = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
            $date = $date->setTimezone(admin_display_timezone());
        }
        return $date->format('M j, Y, g:i A');
    } catch (Exception) {
        return '';
    }
}
