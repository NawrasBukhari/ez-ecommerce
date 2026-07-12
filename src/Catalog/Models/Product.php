<?php

namespace EzEcommerce\Catalog\Models;

use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Database\Factories\ProductFactory;
use EzEcommerce\Marketplace\Models\Vendor;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string|null $public_id
 * @property string|null $name
 * @property string|null $type
 * @property int|null $vendor_id
 * @property ArrayObject<int|string, mixed>|null $metadata
 */
class Product extends CommerceModel
{
    protected static bool $usesPublicId = true;

    use HasFactory;
    use SoftDeletes;

    public const MORPH_ALIAS = 'commerce_product';

    protected $table = 'commerce_products';

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'description',
        'type',
        'vendor_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => AsArrayObject::class,
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'commerce_category_product');
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
