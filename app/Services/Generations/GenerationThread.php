<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Models\Generation;
use Illuminate\Support\Collection;

/**
 * One thread's generations held in memory, answering the questions its page asks: the path from the
 * first prompt to a version, the branches at each step, and where each branch currently ends.
 */
final readonly class GenerationThread
{
    /** @var array<int, Generation> */
    private array $byId;

    /** @var array<int, list<Generation>> children by parent id, oldest first */
    private array $children;

    /**
     * @param  Collection<int, Generation>  $generations  The whole thread, oldest first.
     */
    public function __construct(Collection $generations)
    {
        $byId = [];
        $children = [];

        foreach ($generations as $generation) {
            $byId[$generation->id] = $generation;

            if ($generation->parent_id !== null) {
                $children[$generation->parent_id][] = $generation;
            }
        }

        $this->byId = $byId;
        $this->children = $children;
    }

    /**
     * The versions from the thread's first prompt down to this one, in order.
     *
     * @return list<Generation>
     */
    public function pathTo(Generation $generation): array
    {
        $path = [$generation];
        $seen = [$generation->id => true];
        $current = $generation;

        while ($current->parent_id !== null && isset($this->byId[$current->parent_id]) && ! isset($seen[$current->parent_id])) {
            $current = $this->byId[$current->parent_id];
            $seen[$current->id] = true;
            array_unshift($path, $current);
        }

        return $path;
    }

    /**
     * The alternatives at this step: this version and its siblings, each linked to where its branch
     * currently ends, so switching branch shows that branch's latest image.
     *
     * @return array{position: int, total: int, tips: list<int>}
     */
    public function branchesAt(Generation $generation): array
    {
        $siblings = $generation->parent_id === null ? [$generation] : ($this->children[$generation->parent_id] ?? [$generation]);
        $ids = array_map(fn (Generation $sibling): int => $sibling->id, $siblings);

        return [
            'position' => (int) array_search($generation->id, $ids, true) + 1,
            'total' => count($siblings),
            'tips' => array_map(fn (Generation $sibling): int => $this->tipOf($sibling)->id, $siblings),
        ];
    }

    /**
     * Where this version's branch ends: follow the newest follow-up until there are none.
     */
    public function tipOf(Generation $generation): Generation
    {
        $current = $generation;
        $seen = [];

        while (! isset($seen[$current->id]) && ($this->children[$current->id] ?? []) !== []) {
            $seen[$current->id] = true;
            $followUps = $this->children[$current->id];
            $current = $followUps[count($followUps) - 1];
        }

        return $current;
    }

    /**
     * Every version in the thread as an outline: depth-first from the first prompt, follow-ups oldest
     * first, each with how deep it sits. Drawn as the thread's map of branches.
     *
     * @return list<array{generation: Generation, depth: int}>
     */
    public function outline(): array
    {
        $roots = array_values(array_filter($this->byId, fn (Generation $generation): bool => $generation->parent_id === null || ! isset($this->byId[$generation->parent_id])));
        $outline = [];
        $stack = array_map(fn (Generation $root): array => [$root, 0], array_reverse($roots));

        while ($stack !== []) {
            [$generation, $depth] = array_pop($stack);
            $outline[] = ['generation' => $generation, 'depth' => $depth];

            foreach (array_reverse($this->children[$generation->id] ?? []) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return $outline;
    }

    /**
     * How many branches the thread has: the number of versions nobody has continued from yet.
     */
    public function branchCount(): int
    {
        return count(array_filter($this->byId, fn (Generation $generation): bool => ($this->children[$generation->id] ?? []) === []));
    }
}
