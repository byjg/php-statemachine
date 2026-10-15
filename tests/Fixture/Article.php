<?php

namespace Tests\Fixture;

enum Article: string
{
    case Draft = 'DRAFT';
    case Review = 'REVIEW';
    case Published = 'PUBLISHED';
    case Archived = 'ARCHIVED';
}
