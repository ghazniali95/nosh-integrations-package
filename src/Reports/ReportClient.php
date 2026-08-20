<?php

namespace Nosh\OmniConnect\Reports;

use Nosh\OmniConnect\Exceptions\OmniConnectException;
use Nosh\OmniConnect\Http\ChainClient;

/**
 * POS Order Report service (Integration Middleware API):
 *
 *   GET /v2/chains/{chainCode}/orders/ids        orderIds
 *   GET /v2/chains/{chainCode}/orders/{orderId}  orderDetails
 *
 * Used to reconcile orders after downtime — list ids by status over the last N
 * hours, then pull each order's detail.
 */
class ReportClient extends ChainClient
{
    /**
     * List order identifiers by status ('cancelled' | 'accepted') for the past
     * $pastNumberOfHours (1–24), optionally filtered to one vendor.
     */
    public function orderIds(string $status, int $pastNumberOfHours = 24, ?string $vendorId = null, ?string $chainCode = null): array
    {
        if (! in_array($status, ['cancelled', 'accepted'], true)) {
            throw new OmniConnectException("Report status must be 'cancelled' or 'accepted', got [{$status}].");
        }

        $query = array_filter([
            'status'            => $status,
            'pastNumberOfHours' => max(1, min(24, $pastNumberOfHours)),
            'vendorId'          => $vendorId,
        ], fn ($v) => $v !== null);

        return $this->api->get(
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/orders/ids'),
            $query
        );
    }

    /** Detailed information for a single order id. */
    public function orderDetails(string $orderId, ?string $chainCode = null): array
    {
        return $this->api->get(
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/orders/'.$this->enc($orderId))
        );
    }
}
