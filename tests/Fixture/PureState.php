<?php

namespace Tests\Fixture;

/**
 * A pure enum has no value, so its cases are named by the case name instead.
 */
enum PureState
{
    case Draft;
    case Review;
}
