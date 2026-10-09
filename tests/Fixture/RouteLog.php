<?php

namespace Tests\Fixture;

/**
 * Where the route actions record themselves, so a test can tell which of several moves between
 * the same two states actually ran.
 */
class RouteLog
{
    /** @var string[] */
    public static array $entries = [];

    public static function reset(): void
    {
        self::$entries = [];
    }
}
