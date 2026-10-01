<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\ListPurchaseOrdersRequest;
use App\Http\Requests\V1\ReplacePurchaseOrderLinesRequest;
use App\Http\Requests\V1\StorePurchaseOrderRequest;
use App\Http\Resources\V1\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * E16 to E22. Validates, calls one service method, responds. Rules about
 * status live in PurchaseOrderStatus and the service; domain exceptions are
 * rendered as 409 by bootstrap/app.php, so nothing is caught here.
 */
class PurchaseOrderController extends ApiController
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    /**
     * E16: newest first. `open` means sent or received.
     */
    public function index(ListPurchaseOrdersRequest $request): JsonResponse
    {
        $status = $request->validated('status');

        $orders = PurchaseOrder::query()
            ->when($status === ListPurchaseOrdersRequest::OPEN, fn (Builder $query) => $query->whereIn('status', [
                PurchaseOrderStatus::Sent->value,
                PurchaseOrderStatus::Received->value,
            ]))
            ->when($status !== null && $status !== ListPurchaseOrdersRequest::OPEN, fn (Builder $query) => $query->where('status', $status))
            ->tap($this->withDetails(...))
            // id breaks ties between orders created in the same second.
            ->latest()->latest('id')
            ->paginate($request->perPage());

        return $this->paginated($orders, PurchaseOrderResource::class);
    }

    /**
     * E17
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->supplier(), $request->lines());

        return $this->created(new PurchaseOrderResource($order), "Created {$order->number}.");
    }

    /**
     * E18
     */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load([
            'supplier',
            'lines' => fn ($lines) => $lines->withSum('deliveryLines as received_sum', 'quantity_received'),
            'lines.ingredient',
        ]);

        return $this->success(new PurchaseOrderResource($purchaseOrder));
    }

    /**
     * E19
     */
    public function replaceLines(ReplacePurchaseOrderLinesRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->orders->replaceLines($purchaseOrder, $request->lines());

        return $this->success(new PurchaseOrderResource($order), "Updated the lines of {$order->number}.");
    }

    /**
     * E20
     */
    public function send(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->orders->send($purchaseOrder);

        return $this->success(new PurchaseOrderResource($order), "Sent {$order->number}.");
    }

    /**
     * E21: short-close.
     */
    public function close(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->orders->shortClose($purchaseOrder);

        return $this->success(new PurchaseOrderResource($order), "Closed {$order->number}.");
    }

    /**
     * E22
     */
    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->orders->delete($purchaseOrder);

        return $this->success(null, "Deleted {$purchaseOrder->number}.");
    }

    /**
     * Eager loads for the resource, with delivered totals as one grouped sum (no N+1).
     */
    private function withDetails(Builder $query): void
    {
        $query->with([
            'supplier',
            'lines' => fn ($lines) => $lines->withSum('deliveryLines as received_sum', 'quantity_received'),
            'lines.ingredient',
        ]);
    }
}
