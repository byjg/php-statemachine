<?php

namespace ByJG\StateMachine;

class FiniteStateMachine
{
    /** @var array<string, Transition> Keyed by "CURRENT___DESIRED" */
    protected array $transitionList = [];

    /** @var array<string, State> Keyed by the uppercased state name */
    protected array $stateList = [];

    /** @var (callable(string): object)|null Turns a class name into the collaborator it names */
    protected $resolver = null;

    /** @var array<string, object> Collaborators already built, keyed by class name */
    protected array $instances = [];

    protected bool $throwError = false;

    protected bool $throwErrorOnAmbiguity = false;

    /**
     * @param array $transitionList Each entry is [from, to, condition?, action?]. The condition
     *                              and the action may be instances or the name of a class
     *                              implementing the matching interface.
     * @param callable|null $resolver Builds a collaborator from its class name. Defaults to
     *                                `new $className()`. Pass `[$container, 'get']` to let a
     *                                PSR-11 container build it instead.
     * @return FiniteStateMachine
     * @throws TransitionException If a class name cannot be resolved into the right interface
     */
    public static function createMachine(array $transitionList = [], ?callable $resolver = null): FiniteStateMachine
    {
        $stateList = [];
        $stateMachine = new FiniteStateMachine();
        $stateMachine->resolver = $resolver;

        foreach ($transitionList as $transition) {
            // Keyed by the uppercased name, because that is how State compares itself. Without
            // it a definition mixing "Draft" and "DRAFT" would build two objects for one state.
            $st1 = $stateList[strtoupper($transition[0])] ??= new State($transition[0]);
            $st2 = $stateList[strtoupper($transition[1])] ??= new State($transition[1]);

            $stateMachine->addTransition(
                new Transition(
                    $st1,
                    $st2,
                    $stateMachine->resolve($transition[2] ?? null, TransitionConditionInterface::class),
                    $stateMachine->resolve($transition[3] ?? null, TransitionActionInterface::class)
                )
            );
        }

        return $stateMachine;
    }

    /**
     * Builds a machine from a definition that came from outside PHP.
     *
     * The definition is a plain array, so the format it was written in is not this package's
     * concern: parse YAML, JSON or anything else into an array and hand it over.
     *
     *     transitions:
     *       - from: DRAFT
     *         to: REVIEW
     *         condition: App\Fsm\HasReviewer
     *         action: App\Fsm\NotifyReviewer
     *
     * `from` also accepts a list, which declares the same move out of several states. It is
     * the declarative form of Transition::createMultiple():
     *
     *     transitions:
     *       - from: [LAST_UNITS, OUT_OF_STOCK]
     *         to: RESUPPLIED
     *         condition: App\Fsm\WasFulfilled
     *
     * @param array $definition
     * @param callable|null $resolver See createMachine()
     * @return FiniteStateMachine
     * @throws TransitionException If the definition is malformed or names an unusable class
     */
    public static function fromDefinition(array $definition, ?callable $resolver = null): FiniteStateMachine
    {
        if (!isset($definition["transitions"]) || !is_array($definition["transitions"])) {
            throw new TransitionException("The definition must declare a 'transitions' list");
        }

        $transitionList = [];
        foreach ($definition["transitions"] as $index => $entry) {
            if (!is_array($entry) || !isset($entry["from"], $entry["to"])) {
                throw new TransitionException("The transition #{$index} must declare both 'from' and 'to'");
            }

            foreach ((array)$entry["from"] as $from) {
                $transitionList[] = [$from, $entry["to"], $entry["condition"] ?? null, $entry["action"] ?? null];
            }
        }

        return static::createMachine($transitionList, $resolver);
    }

    /**
     * Passes an instance through, or builds the one a class name refers to.
     *
     * Both interfaces receive everything they operate on as arguments, and conditions are
     * required to be free of side effects, so a single instance per class name is shared by
     * every transition naming it.
     *
     * Resolution happens while the machine is being built, not while it runs: a typo in a
     * class name fails at startup instead of on the one transition nobody exercised.
     *
     * @template T of object
     * @param class-string<T>|T|null $spec
     * @param class-string<T> $interface
     * @return T|null
     * @throws TransitionException
     */
    protected function resolve(object|string|null $spec, string $interface): ?object
    {
        if (is_null($spec)) {
            return null;
        }

        if (is_string($spec)) {
            if (!class_exists($spec)) {
                throw new TransitionException("The class '{$spec}' declared as {$interface} does not exist");
            }

            $spec = $this->instances[$spec] ??= is_null($this->resolver)
                ? new $spec()
                : ($this->resolver)($spec);
        }

        if (!($spec instanceof $interface)) {
            throw new TransitionException(
                get_class($spec) . " must implement {$interface} to be used in a transition"
            );
        }

        return $spec;
    }

    public function throwErrorIfCannotTransition(): static
    {
        $this->throwError = true;
        return $this;
    }

    /**
     * Makes autoTransitionFrom() reject data that satisfies more than one transition.
     *
     * By default the first matching transition wins. Enable this when your conditions
     * are meant to be mutually exclusive and you want to be told when they aren't,
     * instead of silently getting whichever transition was declared first.
     *
     * This evaluates every condition of the current state rather than stopping at the
     * first match, which is safe only because conditions must be free of side effects.
     */
    public function throwErrorIfAmbiguousTransition(): static
    {
        $this->throwErrorOnAmbiguity = true;
        return $this;
    }

    protected function getKey(State $currentState, State $desiredState): string
    {
        return $currentState->getState() . "___" . $desiredState->getState();
    }

    /**
     * @param Transition $transition
     * @return $this
     * @throws TransitionException If a transition between the same pair of states already exists
     */
    public function addTransition(Transition $transition): static
    {
        $key = $this->getKey($transition->getCurrentState(), $transition->getDesiredState());

        if (isset($this->transitionList[$key])) {
            throw new TransitionException(
                "A transition from {$transition->getCurrentState()} to {$transition->getDesiredState()} "
                . "is already defined. Combine the rules into a single condition instead of declaring it twice."
            );
        }

        $this->transitionList[$key] = $transition;

        if (!isset($this->stateList[$transition->getCurrentState()->getState()])) {
            $this->stateList[$transition->getCurrentState()->getState()] = $transition->getCurrentState();
        }
        if (!isset($this->stateList[$transition->getDesiredState()->getState()])) {
            $this->stateList[$transition->getDesiredState()->getState()] = $transition->getDesiredState();
        }
        return $this;
    }

    /**
     * @param array $transitions
     * @return $this
     */
    public function addTransitions(array $transitions): static
    {
        foreach ($transitions as $transition) {
            $this->addTransition($transition);
        }
        return $this;
    }

    public function possibleTransitions(State $currentState): array
    {
        $next = array_map(function ($key, $value) use ($currentState) {
            if (strpos($key, "{$currentState}___") === 0) {
                return $value;
            }
            return null;
        }, array_keys($this->transitionList), array_values($this->transitionList));

        return array_values(array_filter($next));
    }

    public function getTransition(State $currentState, State $desiredState): ?Transition
    {
        if (isset($this->transitionList[$this->getKey($currentState, $desiredState)])) {
            return $this->transitionList[$this->getKey($currentState, $desiredState)];
        }

        return null;
    }

    /**
     * Decides the next state from the current one, based on the data provided.
     *
     * The transitions leaving the current state are evaluated in the order they were
     * added to the machine and the FIRST one whose condition returns true wins. If more
     * than one condition can be true for the same data, the outcome therefore depends on
     * the declaration order; call throwErrorIfAmbiguousTransition() to be told about it
     * instead of relying on that order.
     *
     * @param State $currentState
     * @param array $data
     * @return State|null The next state, or null when nothing matches
     * @throws TransitionException If the data matches no transition and
     *                             throwErrorIfCannotTransition() is enabled, or if it
     *                             matches several and throwErrorIfAmbiguousTransition() is
     */
    public function autoTransitionFrom(State $currentState, array $data): ?State
    {
        $matched = [];

        /**
         * @var Transition $transition
         */
        foreach ($this->possibleTransitions($currentState) as $transition) {
            if (!$transition->runTransitionFunction($data)) {
                continue;
            }

            $matched[] = $transition;

            // First match wins, unless we were asked to detect ambiguity
            if (!$this->throwErrorOnAmbiguity) {
                break;
            }
        }

        if (count($matched) > 1) {
            $candidates = implode(", ", array_map(
                fn (Transition $item): string => $item->getDesiredState()->getState(),
                $matched
            ));
            throw new TransitionException(
                "Ambiguous transition from {$currentState}: the data provided matches {$candidates}"
            );
        }

        if (count($matched) === 1) {
            return $this->arrive($matched[0], $data);
        }

        if ($this->throwError) {
            throw new TransitionException(
                "There is not possible transitions from {$currentState} with the data provided"
            );
        }

        return null;
    }

    /**
     * Builds the state reached by a transition, stamped with where it came from.
     *
     * The caller obtains the resulting state and decides when to process it; the machine
     * never runs the transition action itself.
     */
    protected function arrive(Transition $transition, ?array $data): State
    {
        $state = $transition->getDesiredState($data);
        $state->arrivedThrough($transition->getCurrentState(), $transition->getTransitionAction());

        return $state;
    }

    /**
     * Performs an explicit move and returns the state reached.
     *
     * This is the counterpart of autoTransitionFrom() for when you already know both
     * ends of the move. Unlike canTransition(), which only answers a question, the state
     * returned here carries the transition it came through, so process() runs the action
     * of that specific transition.
     *
     * @param State $currentState
     * @param State $desiredState
     * @param array|null $data
     * @return State|null The state reached, or null when the move is not allowed
     * @throws TransitionException If the move is not allowed and
     *                             throwErrorIfCannotTransition() is enabled
     */
    public function transition(State $currentState, State $desiredState, ?array $data = null): ?State
    {
        $transition = $this->getTransition($currentState, $desiredState);
        $allowed = !empty($transition) && $transition->runTransitionFunction($data);

        if (!$allowed) {
            if ($this->throwError) {
                throw new TransitionException("Cannot transition from {$currentState} to {$desiredState}");
            }

            return null;
        }

        return $this->arrive($transition, $data);
    }

    /**
     * @throws TransitionException
     */
    public function canTransition(State $currentState, State $desiredState, ?array $data = null): bool
    {
        $result = $this->checkIfCanTransition($currentState, $desiredState, $data);

        if ($this->throwError && !$result) {
            throw new TransitionException("Cannot transition from {$currentState} to {$desiredState}");
        }

        return $result;
    }

    protected function checkIfCanTransition(State $currentState, State $desiredState, ?array $data = null): bool
    {
        $transition = $this->getTransition($currentState, $desiredState);

        if (empty($transition)) {
            return false;
        }

        return $transition->runTransitionFunction($data);
    }

    /**
     * Returns a copy of the named state, or null if the machine doesn't know it.
     *
     * The copy is intentional: the caller is free to attach data to the returned state
     * without corrupting the machine definition.
     *
     * @param string $state
     */
    public function state(string $state): ?State
    {
        if (isset($this->stateList[strtoupper($state)])) {
            return clone $this->stateList[strtoupper($state)];
        }

        return null;
    }

    /**
     * A state is initial when no transition leads to it.
     *
     * States are identified by name only. The data a state carries (set by
     * autoTransitionFrom, for instance) is irrelevant to the question.
     */
    public function isInitialState(State $state): bool
    {
        foreach ($this->transitionList as $transition) {
            if ($transition->getDesiredState()->getState() === $state->getState()) {
                return false;
            }
        }

        return true;
    }

    /**
     * A state is final when no transition starts from it.
     *
     * States are identified by name only. The data a state carries (set by
     * autoTransitionFrom, for instance) is irrelevant to the question.
     */
    public function isFinalState(State $state): bool
    {
        foreach ($this->transitionList as $transition) {
            if ($transition->getCurrentState()->getState() === $state->getState()) {
                return false;
            }
        }

        return true;
    }
}
