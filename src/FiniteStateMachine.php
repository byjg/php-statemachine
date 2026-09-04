<?php

namespace ByJG\StateMachine;

use ByJG\StateMachine\Selector\FirstDeclared;
use ByJG\StateMachine\Selector\RejectAmbiguous;

/**
 * A finite state machine over the states named by one enum.
 *
 * The enum is what a machine is: its cases are exactly the states that exist, so a state
 * cannot be invented by a typo in a transition, by a stale string in a definition file, or by
 * a case belonging to some other enum. Every method that names a state accepts a case of that
 * enum, the string it corresponds to, or a State the machine produced earlier, and anything
 * else is rejected where it is written rather than where it is used.
 *
 * The consequence is deliberate: the states of a machine must be known when the code is
 * compiled. Machines whose state names arrive as data at runtime — a workflow whose stages
 * each tenant invents for themselves, say — cannot be expressed with this component.
 */
class FiniteStateMachine
{
    /** @var array<string, Transition> Keyed by "CURRENT___DESIRED" */
    protected array $transitionList = [];

    /** @var class-string<\UnitEnum> The enum naming the states of this machine */
    protected string $enumClass;

    /** @var array<string, State> One per case of the enum, keyed by the uppercased name */
    protected array $stateList = [];

    /** @var (callable(string): object)|null Turns a class name into the collaborator it names */
    protected $resolver = null;

    /** @var array<string, object> Collaborators already built, keyed by class name */
    protected array $instances = [];

    protected bool $throwError = false;

    /** @var TransitionSelectorInterface Decides which move wins when several accept the data */
    protected TransitionSelectorInterface $selector;

    /**
     * @param string $enum The enum whose cases are the states of this machine
     * @throws TransitionException If the enum cannot name a set of states
     */
    public function __construct(string $enum)
    {
        if (!enum_exists($enum)) {
            throw new TransitionException(
                "'{$enum}' is not an enum. The states of a machine are named by the cases of one."
            );
        }

        $reflection = new \ReflectionEnum($enum);

        // A state is named by the case value, so an int-backed enum would name states "1", "2"
        if ($reflection->isBacked() && (string)$reflection->getBackingType() !== "string") {
            throw new TransitionException(
                "{$enum} is backed by {$reflection->getBackingType()}. States are named by the case "
                . "value, so a backed enum must be backed by string."
            );
        }

        /** @var class-string<\UnitEnum> $enum */
        $this->enumClass = $enum;
        $this->selector = new FirstDeclared();

        foreach ($enum::cases() as $case) {
            $name = State::nameOf($case);

            if (isset($this->stateList[$name])) {
                throw new TransitionException(
                    "{$enum} has two cases naming the state '{$name}'. State names are compared "
                    . "uppercased, so they cannot be told apart."
                );
            }

            $this->stateList[$name] = new State($name);
        }
    }

    /**
     * @param string $enum The enum whose cases are the states of this machine
     * @param array $transitionList Each entry is [from, to, condition?, action?, name?, priority?].
     *                              Both ends are named by an enum case or the string it
     *                              corresponds to; the condition and the action may be instances
     *                              or the name of a class implementing the matching interface.
     * @param callable|null $resolver Builds a collaborator from its class name. Defaults to
     *                                `new $className()`. Pass `[$container, 'get']` to let a
     *                                PSR-11 container build it instead.
     * @return FiniteStateMachine
     * @throws TransitionException If a state is not a case of the enum, or a class name cannot
     *                             be resolved into the right interface
     */
    public static function createMachine(
        string $enum,
        array $transitionList = [],
        ?callable $resolver = null
    ): FiniteStateMachine {
        $stateMachine = new FiniteStateMachine($enum);
        $stateMachine->resolver = $resolver;

        foreach ($transitionList as $transition) {
            $built = new Transition(
                $transition[0],
                $transition[1],
                $stateMachine->resolve($transition[2] ?? null, TransitionConditionInterface::class),
                $stateMachine->resolve($transition[3] ?? null, TransitionActionInterface::class),
                $transition[4] ?? null
            );

            $stateMachine->addTransition(
                isset($transition[5]) ? $built->withPriority((int)$transition[5]) : $built
            );
        }

        return $stateMachine;
    }

    /**
     * The name of the state a reference refers to, or an exception when it names none.
     *
     * A case of another enum is the mistake worth catching here: it satisfies every type
     * declaration a union could express, and only the machine knows which enum is its own.
     *
     * @param string|\UnitEnum|State $state
     * @return string
     * @throws TransitionException
     */
    protected function stateName(string|\UnitEnum|State $state): string
    {
        if ($state instanceof \UnitEnum) {
            $this->assertOwnCase($state);
        }

        $name = State::nameOf($state);

        if (!isset($this->stateList[$name])) {
            throw new TransitionException(
                "'{$name}' is not a state of this machine. {$this->enumClass} declares: "
                . implode(", ", array_keys($this->stateList))
            );
        }

        return $name;
    }

    /**
     * Every move joining a pair of states, in declaration order.
     *
     * @param string $currentState
     * @param string $desiredState
     * @return Transition[]
     */
    protected function transitionsBetween(string $currentState, string $desiredState): array
    {
        return array_values(array_filter(
            $this->transitionList,
            fn (string $key): bool => str_starts_with($key, "{$currentState}___{$desiredState}___"),
            ARRAY_FILTER_USE_KEY
        ));
    }

    /**
     * Rejects a case that belongs to some other enum.
     *
     * is_a() rather than instanceof, because the class to compare against is only known at
     * runtime and an instanceof against a dynamic name tells static analysis nothing.
     *
     * @param \UnitEnum $case
     * @throws TransitionException
     */
    protected function assertOwnCase(\UnitEnum $case): void
    {
        if (is_a($case, $this->enumClass)) {
            return;
        }

        throw new TransitionException(
            get_class($case) . "::{$case->name} is not a state of this machine, which is defined "
            . "by {$this->enumClass}"
        );
    }

    /**
     * Builds a machine from a definition that came from outside PHP.
     *
     * The definition is a plain array, so the format it was written in is not this package's
     * concern: parse YAML, JSON or anything else into an array and hand it over.
     *
     *     enum: App\Fsm\ArticleState
     *     transitions:
     *       - from: DRAFT
     *         to: REVIEW
     *         condition: App\Fsm\HasReviewer
     *         action: App\Fsm\NotifyReviewer
     *
     * `enum` is required and names the states: every `from` and `to` in the file must be one of
     * its cases, so a stale or misspelled state name is rejected when the file is read rather
     * than silently becoming a state nothing can reach.
     *
     * `priority` is optional and only means something to a selector that orders by it — see
     * selectWith() and Selector\HighestPriority. It is worth declaring in a file precisely
     * because a file has an order of its own, and leaving the tie-break to that order makes
     * reordering the entries a change in behaviour that nothing in the file admits to.
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
        if (!isset($definition["enum"]) || !is_string($definition["enum"])) {
            throw new TransitionException("The definition must declare the 'enum' naming its states");
        }

        if (!isset($definition["transitions"]) || !is_array($definition["transitions"])) {
            throw new TransitionException("The definition must declare a 'transitions' list");
        }

        $transitionList = [];
        foreach ($definition["transitions"] as $index => $entry) {
            if (!is_array($entry) || !isset($entry["from"], $entry["to"])) {
                throw new TransitionException("The transition #{$index} must declare both 'from' and 'to'");
            }

            foreach ((array)$entry["from"] as $from) {
                $transitionList[] = [
                    $from,
                    $entry["to"],
                    $entry["condition"] ?? null,
                    $entry["action"] ?? null,
                    $entry["name"] ?? null,
                    $entry["priority"] ?? null,
                ];
            }
        }

        return static::createMachine($definition["enum"], $transitionList, $resolver);
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
     *
     * A shorthand for `selectWith(new RejectAmbiguous())`, which is the same policy stated
     * as an object and can be composed with the others.
     */
    public function throwErrorIfAmbiguousTransition(): static
    {
        return $this->selectWith(new RejectAmbiguous());
    }

    /**
     * Replaces the policy deciding which move wins when several accept the data.
     *
     * The machine ships with Selector\FirstDeclared (the default), Selector\RejectAmbiguous
     * and Selector\HighestPriority, and they compose:
     *
     *     $machine->selectWith(new HighestPriority(new RejectAmbiguous()));
     *
     * reads as "the highest priority wins, and an unranked tie is an error".
     *
     * A selector only ever sees transitions whose condition already returned true, and the
     * machine rejects a transition it did not offer, so a selector chooses between legal
     * moves and cannot invent one.
     */
    public function selectWith(TransitionSelectorInterface $selector): static
    {
        $this->selector = $selector;
        return $this;
    }

    protected function getKey(string $currentState, string $desiredState, string $name = ""): string
    {
        return $currentState . "___" . $desiredState . "___" . $name;
    }

    /**
     * Both ends are validated here, which is where a state that the enum does not declare is
     * caught — whether it was written in PHP, read from a definition file, or mistyped.
     *
     * @param Transition $transition
     * @return $this
     * @throws TransitionException If either end is not a state of this machine, or a transition
     *                             between the same pair of states already exists
     */
    public function addTransition(Transition $transition): static
    {
        $from = $this->stateName($transition->getCurrentState());
        $to = $this->stateName($transition->getDesiredState());
        $key = $this->getKey($from, $to, $transition->getName());

        if (isset($this->transitionList[$key])) {
            throw new TransitionException(
                "A transition {$transition->describe()} is already defined. Two moves between the "
                . "same pair of states must be told apart by a name — DRAFT to PAID by PIX and by "
                . "card are two transitions, not one declared twice."
            );
        }

        // An unnamed move is only unambiguous while it is the only way between those two states.
        // Mixing one with a named move leaves the pair identifying neither.
        foreach ($this->transitionsBetween($from, $to) as $other) {
            if ($other->getName() === "" || $transition->getName() === "") {
                throw new TransitionException(
                    "{$from} and {$to} are joined more than once, so every move between them must "
                    . "be named. Name them all, or merge them into one transition."
                );
            }
        }

        $this->transitionList[$key] = $transition;

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

    public function possibleTransitions(string|\UnitEnum|State $currentState): array
    {
        $currentState = $this->stateName($currentState);

        $next = array_map(function ($key, $value) use ($currentState) {
            if (str_starts_with($key, "{$currentState}___")) {
                return $value;
            }
            return null;
        }, array_keys($this->transitionList), array_values($this->transitionList));

        return array_values(array_filter($next));
    }

    public function getTransition(
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        string|\UnitEnum|null $name = null
    ): ?Transition {
        $from = $this->stateName($currentState);
        $to = $this->stateName($desiredState);

        if (!is_null($name)) {
            return $this->transitionList[$this->getKey($from, $to, State::nameOf($name))] ?? null;
        }

        // No name given: the pair identifies the move only while it is the only one
        $matched = $this->transitionsBetween($from, $to);

        if (count($matched) > 1) {
            throw new TransitionException(
                "There is more than one transition from {$from} to {$to}: "
                . implode(", ", array_map(fn (Transition $t): string => $t->getName(), $matched))
                . ". Name the one you mean."
            );
        }

        return $matched[0] ?? null;
    }

    /**
     * Decides the next state from the current one, based on the data provided.
     *
     * The transitions leaving the current state are evaluated in the order they were added to
     * the machine, and which of the matching ones wins is the selector's decision. By default
     * it is the FIRST one whose condition returns true, and evaluation stops there.
     *
     * If more than one condition can be true for the same data, the outcome therefore depends
     * on the declaration order. selectWith() replaces that policy: Selector\RejectAmbiguous to
     * be told about the overlap instead of relying on the order, Selector\HighestPriority to
     * state the order explicitly instead of inheriting it from the source file.
     *
     * @param State $currentState
     * @param array $data
     * @return State|null The next state, or null when nothing matches
     * @throws TransitionException If the data matches no transition and
     *                             throwErrorIfCannotTransition() is enabled, or if the
     *                             selector rejects what it was offered
     */
    public function autoTransitionFrom(string|\UnitEnum|State $currentState, array $data): ?State
    {
        $currentState = $this->state($currentState);

        // What the selector was actually shown. It can only choose from these, and a generator
        // hands them over one at a time: a selector that stops at the first match never causes
        // the remaining conditions to run.
        $offered = [];
        $matching = (function () use ($currentState, $data, &$offered): \Generator {
            foreach ($this->possibleTransitions($currentState) as $transition) {
                if ($transition->runTransitionFunction($data)) {
                    $offered[] = $transition;
                    yield $transition;
                }
            }
        })();

        $chosen = $this->selector->select($currentState, $matching, $data);

        if (is_null($chosen)) {
            if ($this->throwError) {
                throw new TransitionException(
                    "There is not possible transitions from {$currentState} with the data provided"
                );
            }

            return null;
        }

        // The selector owns the policy, not the correctness. Handing back a transition it was
        // not offered would move the machine along a condition that said no, or out of a state
        // it is not in, so it is refused here rather than acted upon.
        if (!in_array($chosen, $offered, true)) {
            throw new TransitionException(
                get_class($this->selector) . " chose {$chosen->describe()}, which is not one of the "
                . "transitions it was offered from {$currentState}. A selector must return a "
                . "transition it received, or null."
            );
        }

        return $this->arrive($chosen, $data);
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
        $state->arrivedThrough($transition);

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
    public function transition(
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        ?array $data = null,
        string|\UnitEnum|null $name = null
    ): ?State {
        $transition = $this->getTransition($currentState, $desiredState, $name);
        $allowed = !empty($transition) && $transition->runTransitionFunction($data);

        if (!$allowed) {
            if ($this->throwError) {
                throw new TransitionException(
                    "Cannot transition from " . $this->stateName($currentState)
                    . " to " . $this->stateName($desiredState)
                );
            }

            return null;
        }

        return $this->arrive($transition, $data);
    }

    /**
     * @throws TransitionException
     */
    public function canTransition(
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        ?array $data = null,
        string|\UnitEnum|null $name = null
    ): bool {
        $result = $this->checkIfCanTransition($currentState, $desiredState, $data, $name);

        if ($this->throwError && !$result) {
            throw new TransitionException(
                "Cannot transition from " . $this->stateName($currentState)
                . " to " . $this->stateName($desiredState)
            );
        }

        return $result;
    }

    protected function checkIfCanTransition(
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        ?array $data = null,
        string|\UnitEnum|null $name = null
    ): bool {
        $transition = $this->getTransition($currentState, $desiredState, $name);

        if (empty($transition)) {
            return false;
        }

        return $transition->runTransitionFunction($data);
    }

    /**
     * A copy of the state a reference names.
     *
     * This cannot fail: every case of the enum is a state of the machine, and anything that is
     * not a case is rejected. There is no "does this state exist" question left to ask.
     *
     * The copy is intentional: the caller is free to attach data to the returned state
     * without corrupting the machine definition.
     *
     * @param string|\UnitEnum|State $state
     * @throws TransitionException If the reference does not name a state of this machine
     */
    public function state(string|\UnitEnum|State $state): State
    {
        return clone $this->stateList[$this->stateName($state)];
    }

    /**
     * A state is initial when no transition leads to it.
     *
     * States are identified by name only. The data a state carries (set by
     * autoTransitionFrom, for instance) is irrelevant to the question.
     */
    public function isInitialState(string|\UnitEnum|State $state): bool
    {
        $name = $this->stateName($state);

        foreach ($this->transitionList as $transition) {
            if ($transition->getDesiredState()->getState() === $name) {
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
    public function isFinalState(string|\UnitEnum|State $state): bool
    {
        $name = $this->stateName($state);

        foreach ($this->transitionList as $transition) {
            if ($transition->getCurrentState()->getState() === $name) {
                return false;
            }
        }

        return true;
    }
}
