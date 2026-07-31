<?php

namespace Tests\Fixture;

/**
 * A perfectly valid enum that belongs to no machine here. It satisfies every type declaration
 * a `string|UnitEnum` union could express, which is why the machine has to reject it itself.
 */
enum Unrelated: string
{
    case Nonsense = 'NONSENSE';
    case Draft = 'DRAFT';
}
