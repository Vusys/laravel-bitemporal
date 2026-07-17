<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;

/**
 * Resolves the temporal column names, dimensions, and recorded-time flag for a
 * model. Values come from per-model properties when declared, otherwise from
 * the package config.
 */
trait HasTemporalEntity
{
    public function tracksRecordedTime(): bool
    {
        return ! property_exists($this, 'tracksRecordedTime') || $this->tracksRecordedTime;
    }

    /**
     * @return array<int, string>
     */
    public function temporalDimensions(): array
    {
        return property_exists($this, 'temporalDimensions') ? $this->temporalDimensions : [];
    }

    public function validFromColumn(): string
    {
        return $this->temporalColumn('valid_from', 'validFromColumn');
    }

    public function validToColumn(): string
    {
        return $this->temporalColumn('valid_to', 'validToColumn');
    }

    public function recordedFromColumn(): string
    {
        return $this->temporalColumn('recorded_from', 'recordedFromColumn');
    }

    public function recordedToColumn(): string
    {
        return $this->temporalColumn('recorded_to', 'recordedToColumn');
    }

    public function isRetractionColumn(): string
    {
        return $this->temporalColumn('is_retraction', 'isRetractionColumn');
    }

    public function temporalMetadata(): TemporalEntityMetadata
    {
        return new TemporalEntityMetadata(
            $this->validFromColumn(),
            $this->validToColumn(),
            $this->recordedFromColumn(),
            $this->recordedToColumn(),
            $this->isRetractionColumn(),
            $this->tracksRecordedTime(),
            $this->temporalDimensions(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function temporalColumnMap(): array
    {
        return $this->temporalMetadata()->columnMap();
    }

    /**
     * The relation to the entity this temporal table versions.
     *
     * The conventional case declares the entity as a class-string property and
     * lets the library build the BelongsTo on the natural `<entity>_id` key —
     * the same column `bitemporalForeignFor()` emits, so the two cannot drift:
     *
     *     protected string $temporalEntity = Employee::class;
     *
     * A polymorphic parent or a non-conventional foreign key cannot be named by
     * a single class-string, so those models override this method and return the
     * relation directly (e.g. `return $this->morphTo();`).
     *
     * @return BelongsTo<Model, Model>|MorphTo<Model, Model>
     */
    public function temporalEntityRelation(): BelongsTo|MorphTo
    {
        // Read through a dynamic name so overriding models (which declare no
        // $temporalEntity property) resolve to null rather than a static error.
        $property = 'temporalEntity';
        $entity = property_exists($this, $property) ? $this->{$property} : null;

        if (! is_string($entity) || ! is_a($entity, Model::class, true)) {
            throw TemporalConfigurationException::missingTemporalEntity(static::class);
        }

        return self::buildTemporalEntityRelation($this, $entity);
    }

    /**
     * Build the entity BelongsTo from a base-Model receiver so the relation's
     * declaring-model type stays invariant-safe (BelongsTo<Model, Model>) rather
     * than binding to a concrete `$this` — which the invariant TDeclaringModel
     * template rejects for Pivot subclasses.
     *
     * @param  class-string<Model>  $entity
     * @return BelongsTo<Model, Model>
     */
    private static function buildTemporalEntityRelation(Model $model, string $entity): BelongsTo
    {
        $reference = new $entity;

        return $model->belongsTo($entity, $reference->getForeignKey(), $reference->getKeyName());
    }

    private function temporalColumn(string $configKey, string $property): string
    {
        if (property_exists($this, $property)) {
            return $this->{$property};
        }

        $value = config("bitemporal.columns.{$configKey}", $configKey);

        return is_string($value) ? $value : $configKey;
    }
}
