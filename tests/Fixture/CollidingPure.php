<?php

namespace Tests\Fixture;

/**
 * PHP accepts these as two distinct cases — case names are case-sensitive — but state names are
 * compared uppercased, so both would name the state 'A'.
 */
enum CollidingPure
{
    case A;
    case a;
}
