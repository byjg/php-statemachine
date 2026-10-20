<?php

namespace Tests\Fixture;

class PaidByPix extends PaidBy
{
    public function __construct()
    {
        parent::__construct('PIX');
    }
}
