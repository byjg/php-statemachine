<?php

namespace ByJG\StateMachine;

use Closure;

class State
{
    protected ?string $state = null;

    protected ?Closure $stateFunction;

    protected ?array $data = null;

    /**
     * @param string $state
     * @param Closure|null $stateFunction
     */
    public function __construct(string $state, Closure $stateFunction = null)
    {
        $this->state = $state;
        $this->stateFunction = $stateFunction;
    }

    public function __toString()
    {
        return $this->getState();
    }

    public function getState(): string
    {
        return strtoupper($this->state);
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData($data): void
    {
        $this->data = $data;
    }

    public function process(): void
    {
        if (!empty($this->stateFunction)) {
            call_user_func_array($this->stateFunction, [$this->data]);
        }
    }
}
