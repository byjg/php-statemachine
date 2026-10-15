<?php

namespace Tests\Fixture;

/**
 * States are named by the case value, so this one would name them "1" and "2".
 */
enum IntBacked: int
{
    case One = 1;
    case Two = 2;
}
