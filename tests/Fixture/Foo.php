<?php

namespace Tests\Fixture;

/**
 * The simplest enum that can define a machine: no backing type, no values, nothing to write
 * twice. The case names are the state names.
 */
enum Foo
{
    case A;
    case B;
}
