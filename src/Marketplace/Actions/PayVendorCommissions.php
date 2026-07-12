<?php

namespace EzEcommerce\Marketplace\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\VendorCommissionStatus;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Marketplace\Models\VendorCommission;
use EzEcommerce\Marketplace\Models\VendorPayout;
use Illuminate\Support\Facades\DB;

final class PayVendorCommissions
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<int>|null  $commissionIds
     * @return array{payout: VendorPayout, paid_count: int}
     */
    public function execute(Vendor $vendor, ?array $commissionIds = null): array
    {
        return DB::transaction(function () use ($vendor, $commissionIds): array {
            $query = VendorCommission::query()
                ->where('vendor_id', $vendor->id)
                ->where('status', VendorCommissionStatus::Pending)
                ->lockForUpdate();

            if ($commissionIds !== null && $commissionIds !== []) {
                $query->whereIn('id', $commissionIds);
            }

            $commissions = $query->get();
            if ($commissions->isEmpty()) {
                abort(422, 'No pending commissions to pay.');
            }

            $currency = (string) $commissions->first()->currency;
            $totalMinor = $commissions->sum('amount_minor');

            $payout = VendorPayout::query()->create([
                'vendor_id' => $vendor->id,
                'amount_minor' => $totalMinor,
                'currency' => $currency,
                'commission_count' => $commissions->count(),
                'paid_at' => $this->clock->now(),
            ]);

            VendorCommission::query()
                ->whereIn('id', $commissions->pluck('id'))
                ->update([
                    'status' => VendorCommissionStatus::Paid,
                    'payout_id' => $payout->id,
                ]);

            return [
                'payout' => $payout->fresh(),
                'paid_count' => $commissions->count(),
            ];
        });
    }
}
