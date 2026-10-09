<?php

namespace Tests\Fixture;

/**
 * Two cases that name the same state once uppercased.
 */
enum Colliding: string
{
    case Lower = 'draft';
    case Upper = 'DRAFT';
}
