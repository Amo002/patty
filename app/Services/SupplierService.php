<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suppliers (FR-1, F1, F2). Plain contact records: no state to protect.
 */
class SupplierService
{
    /**
     * A page of suppliers by name (D-032).
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Supplier::query()->orderBy('name')->orderBy('id')->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data  validated name and optional email and phone
     */
    public function create(array $data): Supplier
    {
        $supplier = DB::transaction(fn () => Supplier::create($data));

        Log::channel('catalog')->info('Supplier created', ['supplier' => $supplier->ulid, 'name' => $supplier->name]);

        return $supplier;
    }

    /**
     * Keys absent from $data are left alone; null clears email or phone.
     * A no-op update writes no audit row and no log line.
     *
     * @param  array<string, mixed>  $data  only the validated keys the client sent
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        DB::transaction(fn () => $supplier->update($data));

        if ($supplier->wasChanged()) {
            Log::channel('catalog')->info('Supplier updated', [
                'supplier' => $supplier->ulid,
                'changed' => array_keys($supplier->getChanges()),
            ]);
        }

        return $supplier;
    }
}
