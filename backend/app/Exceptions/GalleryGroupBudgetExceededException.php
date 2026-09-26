<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Controlled failure for a gallery-group traversal that cannot be answered
 * within its explicit budget.
 *
 * `gallery_groups.parent_id` is self-referencing and unconstrained, so the
 * persisted hierarchy can be arbitrarily large. A traversal therefore has a
 * hard node budget; when the budget cannot answer the question truthfully the
 * traversal aborts with this exception instead of silently returning a partial
 * result. Callers fail closed (empty tree / denied scope) and log the event, so
 * an over-budget hierarchy can never expose more than authorized and can never
 * be answered by exhausting memory.
 *
 * Depth truncation is not an error: a deeper level is simply not exposed (the
 * deeper part of the result is empty), which is fail-closed by construction.
 */
final class GalleryGroupBudgetExceededException extends RuntimeException
{
    /** The node budget cannot cover the requested traversal. */
    public const KIND_NODES = 'nodes';

    public function __construct(
        string $message,
        private readonly string $kind = self::KIND_NODES,
        private readonly int $limit = 0,
        private readonly int $requested = 0,
    ) {
        parent::__construct($message);
    }

    /**
     * The traversal was seeded with more roots than the node budget allows.
     */
    public static function forRoots(int $limit, int $rootCount): self
    {
        return new self(
            "Gallery group traversal budget exceeded: {$rootCount} roots requested, budget is {$limit} nodes.",
            self::KIND_NODES,
            $limit,
            $rootCount,
        );
    }

    /**
     * The traversal would have to drop reachable nodes to stay in budget.
     */
    public static function forNodes(int $limit, int $visited): self
    {
        return new self(
            "Gallery group traversal budget exceeded: more than {$limit} nodes reachable, already visited {$visited}.",
            self::KIND_NODES,
            $limit,
            $visited,
        );
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function requested(): int
    {
        return $this->requested;
    }
}
