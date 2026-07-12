<?php

namespace EzEcommerce\Stores;

use EzEcommerce\Stores\Contracts\StoreContext;
use EzEcommerce\Stores\Models\Store;
use Illuminate\Http\Request;

final class DefaultStoreContext implements StoreContext
{
    private ?Store $resolved = null;

    private bool $resolvedFlag = false;

    public function __construct(
        private readonly Request $request,
    ) {}

    public function current(): ?Store
    {
        if ($this->resolvedFlag) {
            return $this->resolved;
        }

        $this->resolvedFlag = true;

        if (! config('ez-ecommerce.features.multi_store', false)) {
            return $this->resolved = null;
        }

        $header = $this->request->header('X-Commerce-Store');
        if (is_string($header) && $header !== '') {
            $this->resolved = Store::query()
                ->where('public_id', $header)
                ->orWhere('slug', $header)
                ->first();

            return $this->resolved;
        }

        $defaultId = config('ez-ecommerce.multi_store.default_store_id');
        if ($defaultId !== null) {
            $this->resolved = Store::query()->find($defaultId);
        }

        return $this->resolved;
    }

    public function id(): ?int
    {
        return $this->current()?->id;
    }
}
