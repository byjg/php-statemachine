<?php

namespace Tests\Fixture;

/**
 * Case values that are not uppercase. State names are compared uppercased, so these have to
 * keep matching themselves.
 */
enum LowerCased: string
{
    case Draft = 'draft';
    case Review = 'review';
}
