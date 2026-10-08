<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

final class Urls
{
    /**
     * Drops scheme and host but keeps path (including a sub-directory install) and query,
     * so links keep working behind proxies or under another host name.
     */
    public static function relative(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['path'])) {
            return $url;
        }

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
