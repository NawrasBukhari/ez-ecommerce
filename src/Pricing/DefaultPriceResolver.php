<?php

namespace EzEcommerce\Pricing;

use DateTimeImmutable;
use EzEcommerce\Catalog\Contracts\Purchasable;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Pricing\Contracts\PriceResolver;
use EzEcommerce\Pricing\Data\PriceQuote;
use EzEcommerce\Pricing\Data\PricingContext;
use EzEcommerce\Pricing\Models\Price;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class DefaultPriceResolver implements PriceResolver
{
    public function resolve(Purchasable $purchasable, PricingContext $context): PriceQuote
    {
        $at = $context->at ?? new DateTimeImmutable;
        $precedence = config('ez-ecommerce.pricing.precedence', ['customer', 'customer_group', 'price_list', 'sale', 'base']);

        foreach ($precedence as $source) {
            $price = $this->findForSource($purchasable, $context, $source, $at);
            if ($price !== null) {
                return new PriceQuote(
                    unitPrice: Money::fromMinor($price->amount_minor, $price->currency),
                    source: $source,
                    priceId: $price->id,
                    metadata: $price->metadata?->toArray() ?? [],
                );
            }
        }

        throw new RuntimeException('No price found for purchasable '.$purchasable->purchasableId());
    }

    private function findForSource(Purchasable $purchasable, PricingContext $context, string $source, DateTimeImmutable $at): ?Price
    {
        $query = Price::query()
            ->where('priceable_type', $purchasable->purchasableType())
            ->where('priceable_id', $this->resolvePriceableId($purchasable))
            ->where('currency', $context->currency)
            ->where('type', $source)
            ->where(function ($q) use ($at): void {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at);
            })
            ->where(function ($q) use ($at): void {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $at);
            });

        return match ($source) {
            'customer' => $context->customer
                ? $query->where('customer_id', $context->customer->id)->first()
                : null,
            'customer_group' => $context->customerGroup
                ? $query->where('customer_group_id', $context->customerGroup->id)->first()
                : null,
            'price_list' => $context->priceList
                ? $query->where('price_list_id', $context->priceList->id)->first()
                : null,
            'sale', 'base' => $query
                ->whereNull('customer_id')
                ->whereNull('customer_group_id')
                ->whereNull('price_list_id')
                ->orderByDesc('id')
                ->first(),
            default => null,
        };
    }

    private function resolvePriceableId(Purchasable $purchasable): int
    {
        if ($purchasable instanceof Model) {
            return $purchasable->getKey();
        }

        throw new RuntimeException('Purchasable must be an Eloquent model.');
    }
}
