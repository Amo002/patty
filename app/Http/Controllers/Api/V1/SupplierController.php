<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\ListSuppliersRequest;
use App\Http\Requests\V1\StoreSupplierRequest;
use App\Http\Requests\V1\UpdateSupplierRequest;
use App\Http\Resources\V1\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;

/**
 * E7 to E10. Validates, calls one SupplierService method and responds.
 */
class SupplierController extends ApiController
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(ListSuppliersRequest $request): JsonResponse
    {
        return $this->paginated($this->suppliers->paginate($request->perPage()), SupplierResource::class);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        return $this->created(new SupplierResource($this->suppliers->create($request->validated())), 'Supplier created.');
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return $this->success(new SupplierResource($supplier));
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        return $this->success(new SupplierResource($this->suppliers->update($supplier, $request->validated())), 'Supplier updated.');
    }
}
