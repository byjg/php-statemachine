<?php

namespace Tests\Fixture;

/**
 * Both halves of the stock example: where a product sits, and what is being done about it.
 */
enum Stock: string
{
    case Start = '__VOID__';
    case InStock = 'IN_STOCK';
    case LastUnits = 'LAST_UNITS';
    case OutOfStock = 'OUT_OF_STOCK';
    case Depleted = 'DEPLETED';
    case NotRequested = 'NOT_REQUESTED';
    case RequestedResupply = 'REQUESTED_RESUPPLY';
    case Resupplied = 'RESUPPLIED';
    case Unavailable = 'UNAVAILABLE';
}
