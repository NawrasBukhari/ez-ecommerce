<?php

namespace EzEcommerce\Catalog\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends CommerceModel
{
    protected static bool $usesPublicId = true;

    use SoftDeletes;

    public const MORPH_ALIAS = 'commerce_product';

    protected $table = 'commerce_products';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
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

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'commerce_category_product');
    }
}
