<?php

declare(strict_types=1);

/**
 * Message templates for package exceptions. The parameter set in :placeholder
 * tokens is stable across the 1.x line; wording may be refined. Publish
 * lang/vendor/bitemporal/{locale}/messages.php to localise.
 *
 * See docs/09a-exception-catalogue.md for the full catalogue.
 */
return [
    'configuration' => [
        'soft_deletes' => 'Model :model cannot use both Bitemporal and SoftDeletes traits. Use retract() or forceDeleteHistory() instead.',
        'relation_type' => 'temporalEntity() on :model must return BelongsTo or MorphTo; found :type.',
        'missing_temporal_entity' => 'temporal model :model must define a temporalEntity() relation',
        'disabled_pivot_method' => ':method() is disabled on a temporal pivot relation because it would destroy history; use :useInstead instead',
    ],

    'invalid_period' => [
        'inverted' => 'valid_from (:from) must be before valid_to (:to).',
        'zero_length' => 'Zero-length valid period rejected. Set spells.allow_zero_length = true to permit.',
        'not_forward_dated' => 'changeEffectiveFrom requires validFrom >= now() (:validFrom < :now). Use correct for retroactive writes.',
    ],

    'missing_dimension' => [
        'pending_where' => 'Write rejected: builder has where(...) constraints. Writes are not scoped queries; pass dimensions via forDimensions() and values via attributes:.',
        'incomplete' => "Dimension ':column' is required but missing from attributes for :model.",
        'conflict' => "Dimension conflict on ':column': forDimensions and attributes disagree.",
    ],

    'overlap' => [
        'between_segments' => 'Overlap detected: valid periods of segments :a and :b intersect.',
        'internal' => 'Supplied timeline has internal overlap at indices :i and :j.',
    ],

    'cardinality' => [
        'expected_one_found_many' => 'Expected a single :model row but found :count.',
        'expected_one_found_none' => 'Expected a single :model row but found none.',
        'no_assignment_to_correct' => 'correctAssignment requires an existing assignment to correct; none found for tuple :tuple. Use attachFor to create the assignment.',
        'no_assignment_to_detach' => 'detachAt requires an open-ended current assignment to end; none found for tuple :tuple.',
    ],

    'write_conflict' => [
        'lock_timeout' => 'Lock timeout acquiring temporal write lock for :entity (timeout :ms ms).',
        'entity_missing' => 'Cannot lock temporal entity :model#:id: row not found.',
        'expectation_failed' => "optimistic check failed: the current value of ':column' is not what was expected; another write got there first",
        'idempotency_conflict' => "idempotency key ':key' was already used with different parameters",
    ],

    'unsupported_database' => [
        'btree_gist' => 'btree_gist extension not available; required for exclusion constraints.',
        'advisory_sqlite' => 'SQLite does not support advisory locks; falling back to parent_row.',
    ],

    'domain' => [
        'invariant' => 'Internal: :assertion failed at :algorithm. Report this with reproduction.',
        'clock_skew' => 'Clock skew at :entity: max(recorded_from) = :persisted, now() = :now, drift :drift ms exceeds writes.clock_skew_tolerance_ms (:tolerance ms).',
    ],
];
