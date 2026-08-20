<?php

namespace Nosh\OmniConnect\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nosh\OmniConnect\Events\CatalogImportStatusReceived;
use Nosh\OmniConnect\Events\MenuImportRequested;
use Nosh\OmniConnect\Events\VendorAvailabilityChanged;
use Nosh\OmniConnect\Orders\OrderIntake;

/**
 * The inbound Plugin API — endpoints Delivery Hero calls on US. Mounted by the
 * service provider under the configured prefix, behind VerifyMiddlewareJwt.
 *
 * Endpoints (from the POS Plugin API spec):
 *   POST order/{remoteId}                                            Dispatch Order
 *   PUT  remoteId/{remoteId}/remoteOrder/{remoteOrderId}/posOrderStatus  Update Order Status
 *   PUT  remoteId/{remoteId}/availability                           Vendor availability
 *   GET  menuimport/{remoteId}                                      Trigger Menu Import
 *   POST catalog-import-callback                                    Catalog Import status
 */
class PluginController
{
    public function __construct(protected OrderIntake $intake)
    {
    }

    /** New order. Must acknowledge with our remoteOrderId (mandatory). */
    public function dispatchOrder(Request $request, string $remoteId): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();

        // Quick validation: a usable order token must be present.
        if (empty($payload['token'])) {
            return response()->json(
                ['reason' => 'VALIDATION_ERROR', 'message' => 'Missing order token.'],
                400
            );
        }

        $ack = $this->intake->receiveOrder($remoteId, $payload);

        return response()->json($ack, 200);
    }

    /** Status update for an already-dispatched order (cancellation, picked-up…). */
    public function orderStatus(Request $request, string $remoteId, string $remoteOrderId): JsonResponse
    {
        $this->intake->updateStatus($remoteId, $remoteOrderId, $request->json()->all() ?: $request->all());

        return response()->json(['status' => 'OK'], 200);
    }

    /** Vendor availability change notification. */
    public function vendorAvailability(Request $request, string $remoteId): JsonResponse
    {
        $body = $request->json()->all() ?: $request->all();

        event(new VendorAvailabilityChanged(
            remoteId: $remoteId,
            timestamp: $body['timestamp'] ?? null,
            closures: $body['closures'] ?? [],
            raw: $body,
        ));

        return response()->json(['status' => 'OK'], 200);
    }

    /**
     * Delivery Hero asks us to (re)push this vendor's menu. The required
     * vendorCode + menuImportId query params must be used when replying.
     */
    public function menuImport(Request $request, string $remoteId): JsonResponse
    {
        event(new MenuImportRequested(
            remoteId: $remoteId,
            vendorCode: $request->query('vendorCode'),
            menuImportId: $request->query('menuImportId'),
            raw: $request->all(),
        ));

        return response()->json(['status' => 'ACCEPTED'], 202);
    }

    /** Outcome of a catalog import we submitted. 200 = keep listening. */
    public function catalogImportCallback(Request $request): JsonResponse
    {
        $body = $request->json()->all() ?: $request->all();

        event(new CatalogImportStatusReceived(
            catalogImportId: $body['catalogImportId'] ?? null,
            status: $body['status'] ?? null,
            message: $body['message'] ?? null,
            details: $body['details'] ?? [],
            raw: $body,
        ));

        return response()->json(null, 200);
    }
}
