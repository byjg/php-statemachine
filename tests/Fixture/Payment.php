<?php

namespace Tests\Fixture;

enum Payment: string
{
    case Draft = 'DRAFT';
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';
}
