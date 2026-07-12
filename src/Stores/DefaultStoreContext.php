<?php

namespace EzEcommerce\Stores;

use EzEcommerce\Stores\Contracts\StoreContext;
use EzEcommerce\Stores\Models\Store;
use Illuminate\Http\Request;

final class DefaultStoreContext implements StoreContext
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function current(): ?Store
    {
        if (! config('ez-ecommerce.features.multi_store', false)) {
            return null;
        }

        $header = $this->request->header('X-Commerce-Store');
        if (is_string($header) && $header !== '') {
            return Store::query()
                ->where('public_id', $header)
                ->orWhere('slug', $header)
                ->first();
        }

        $defaultId = config('ez-ecommerce.multi_store.default_store_id');
        if ($defaultId !== null) {
            return Store::query()->find($defaultId);
        }

        return null;
    }

    public function id(): ?int
    {
        return $this->current()?->id;
    }
}
