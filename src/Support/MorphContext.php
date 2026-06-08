<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A polymorphic entity reference (morph type + id) that identifies a parent row
 * without holding the model instance. Its string form `"{type}:{id}"` matches
 * the key produced by keyByTemporalEntityReference().
 */
final readonly class MorphContext implements \Stringable
{
    public function __construct(
        public string $type,
        public int|string $id,
    ) {}

    public static function fromModel(Model $model): self
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new \InvalidArgumentException('temporal entity key must be an int or string; got '.get_debug_type($key));
        }

        return new self($model->getMorphClass(), $key);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && (string) $this->id === (string) $other->id;
    }

    public function __toString(): string
    {
        return $this->type.':'.$this->id;
    }
}
