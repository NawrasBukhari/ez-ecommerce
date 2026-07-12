<?php

namespace EzEcommerce\Pricing\Data;

use EzEcommerce\Core\Money\Money;

final readonly class PriceQuote
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public Money $unitPrice,
        public string $source,
        public ?int $priceId = null,
        public array $metadata = [],
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'unit_minor' => $this->unitPrice->minorAmount,
            'currency' => $this->unitPrice->currency,
            'source' => $this->source,
            'price_id' => $this->priceId,
        ], JSON_THROW_ON_ERROR));
    }
}
