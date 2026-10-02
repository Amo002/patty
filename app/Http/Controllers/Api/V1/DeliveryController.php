<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\PaginationRequest;
use App\Http\Requests\V1\StoreDeliveryRequest;
use App\Http\Resources\V1\DeliveryResource;
use App\Http\Resources\V1\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\ReceivingService;
use Illuminate\Http\JsonResponse;

/**
 * E23 and E24. Validates, calls ReceivingService, responds. 409 and 422 domain
 * errors are rendered by bootstrap/app.php, so nothing is caught here.
 */
class DeliveryController extends ApiController
{
    public function __construct(private readonly ReceivingService $receiving) {}

    /**
     * E23: 201 with the delivery AND the updated order, so the UI needs no second request.
     */
    public function store(StoreDeliveryRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $delivery = $this->receiving->receive($purchaseOrder, $request->lines(), $request->receivedAt(), $request->note());

        $order = $purchaseOrder->refresh()->load(PurchaseOrder::detailRelations());

        return $this->created([
            'delivery' => (new DeliveryResource($delivery))->resolve($request),
            'purchase_order' => (new PurchaseOrderResource($order))->resolve($request),
        ], "Recorded {$delivery->number} for {$order->number}.");
    }

    /**
     * E24: newest first by the time the goods arrived; id breaks ties.
     */
    public function index(PaginationRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $deliveries = $purchaseOrder->deliveries()
            ->with('lines.purchaseOrderLine.ingredient')
            ->latest('received_at')->latest('id')
            ->paginate($request->perPage());

        return $this->paginated($deliveries, DeliveryResource::class);
    }
}
