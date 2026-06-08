<?php

declare(strict_types=1);

namespace Bitemporal\Concerns;

use Bitemporal\Support\TemporalEntityMetadata;

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

    private function temporalColumn(string $configKey, string $property): string
    {
        if (property_exists($this, $property)) {
            return $this->{$property};
        }

        $value = config("bitemporal.columns.{$configKey}", $configKey);

        return is_string($value) ? $value : $configKey;
    }
}
