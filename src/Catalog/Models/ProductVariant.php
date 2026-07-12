<?php

namespace EzEcommerce\Catalog\Models;

use EzEcommerce\Catalog\Contracts\Purchasable;
use EzEcommerce\Catalog\Contracts\Shippable;
use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Catalog\Contracts\Taxable;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Database\Factories\ProductVariantFactory;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Pricing\Models\Price;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends CommerceModel implements Purchasable, Shippable, Stockable, Taxable
{
    protected static bool $usesPublicId = true;

    use HasFactory;
    use SoftDeletes;

    public const MORPH_ALIAS = 'commerce_product_variant';

    protected $table = 'commerce_product_variants';

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => AsArrayObject::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }

    public function inventoryBalances(): MorphMany
    {
        return $this->morphMany(InventoryBalance::class, 'stockable');
    }

    public function purchasableId(): string
    {
        return $this->public_id;
    }

    public function purchasableType(): string
    {
        return self::MORPH_ALIAS;
    }

    public function purchasableName(): string
    {
        return $this->name ?? $this->product?->name ?? '';
    }

    /** @return array<string, mixed> */
    public function purchasableMetadata(): array
    {
        return $this->metadata?->toArray() ?? [];
    }

    public function stockIdentifier(): string
    {
        return $this->public_id;
    }

    public function requiresShipping(): bool
    {
        return ($this->product?->type ?? 'physical') === 'physical';
    }

    public function weightGrams(): ?int
    {
        $weight = $this->metadata?->get('weight_grams');

        return is_int($weight) ? $weight : null;
    }

    public function taxCategory(): ?string
    {
        $category = $this->metadata?->get('tax_category');

        return is_string($category) ? $category : null;
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
