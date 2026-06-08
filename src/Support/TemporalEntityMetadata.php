<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Support;

/**
 * Resolved temporal column names and flags for a single model. Centralises
 * column resolution so the builder, relations, and (later) the writer share
 * one source of truth.
 */
final readonly class TemporalEntityMetadata
{
    /**
     * @param  array<int, string>  $dimensions
     */
    public function __construct(
        public string $validFrom,
        public string $validTo,
        public string $recordedFrom,
        public string $recordedTo,
        public string $isRetraction,
        public bool $tracksRecordedTime,
        public array $dimensions,
    ) {}

    /**
     * @return array<string, string>
     */
    public function columnMap(): array
    {
        return [
            'valid_from' => $this->validFrom,
            'valid_to' => $this->validTo,
            'recorded_from' => $this->recordedFrom,
            'recorded_to' => $this->recordedTo,
            'is_retraction' => $this->isRetraction,
        ];
    }
}
