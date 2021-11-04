<?php

namespace ByJG\StateMachine;

class State
{
    protected $state = null;

    /**
     * @param string $state
     */
    public function __construct($state)
    {
        $this->state = $state;
    }

    public function __toString()
    {
        return $this->getState();
    }

    public function getState()
    {
        return strtoupper($this->state);
    }
}