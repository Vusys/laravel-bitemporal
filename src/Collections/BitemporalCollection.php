<?php

declare(strict_types=1);

namespace Bitemporal\Collections;

use Illuminate\Database\Eloquent\Collection;

/**
 * Collection returned by temporal queries. Entity-keying and grouping helpers
 * are added in Phase 3.
 *
 * @template TKey of array-key
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Collection<TKey, TModel>
 */
class BitemporalCollection extends Collection {}
