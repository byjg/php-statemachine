<?php

namespace ByJG\StateMachine;

class State
{
    protected string $state;

    protected ?StateActionInterface $stateAction;

    protected ?array $data = null;

    /**
     * @param string $state
     * @param StateActionInterface|null $stateAction
     */
    public function __construct(string $state, ?StateActionInterface $stateAction = null)
    {
        $this->state = $state;
        $this->stateAction = $stateAction;
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
        if (!empty($this->stateAction)) {
            $this->stateAction->execute($this->data);
        }
    }
}
