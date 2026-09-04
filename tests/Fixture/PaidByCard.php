<?php

namespace Tests\Fixture;

class PaidByCard extends PaidBy
{
    public function __construct()
    {
        parent::__construct('CARD');
    }
}
