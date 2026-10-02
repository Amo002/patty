@extends('layouts.app')

@section('title', 'New purchase order')

@push('scripts')
    @include('pages.purchasing.head', ['script' => 'purchase-order-new'])
@endpush

@section('actions')
    <a class="btn btn-secondary" href="/purchase-orders">Back to orders</a>
@endsection

@section('content')
<div class="stack" x-data="purchaseOrderNew">

    <div class="error-banner" role="alert" x-show="loadError" x-cloak>
        <x-icon name="alert" />
        <span class="grow">
            <span x-text="loadError && loadError.message"></span>
            <span class="text-xs" x-show="loadError && loadError.requestId" x-text="'Request id: ' + (loadError && loadError.requestId)"></span>
        </span>
        <button type="button" class="btn btn-secondary" @click="loadLists()"><x-icon name="refresh" class="icon-sm" /> Retry</button>
    </div>

    <div class="card" x-show="loading" aria-busy="true">
        <div class="card-body stack">
            <div class="skeleton skeleton-line w-25"></div>
            <div class="skeleton skeleton-line"></div>
            <div class="skeleton skeleton-block"></div>
        </div>
    </div>

    <div x-show="! loading && ! loadError && suppliers.length === 0" x-cloak>
        <x-empty-state title="No suppliers yet" text="An order goes to a supplier. Add one first." icon="supplier">
            <a class="btn btn-primary" href="/suppliers"><x-icon name="plus" class="icon-sm" /> Add a supplier</a>
        </x-empty-state>
    </div>

    <div x-show="! loading && ! loadError && suppliers.length > 0 && ingredients.length === 0" x-cloak>
        <x-empty-state title="No ingredients yet" text="An order needs at least one ingredient line. Add ingredients first." icon="ingredients">
            <a class="btn btn-primary" href="/ingredients"><x-icon name="plus" class="icon-sm" /> Add an ingredient</a>
        </x-empty-state>
    </div>

    <form class="card" novalidate @submit.prevent="submit()"
          x-show="! loading && ! loadError && suppliers.length > 0 && ingredients.length > 0" x-cloak>
        <div class="card-body stack">
            <div class="field" x-id="['supplier']">
                <label :for="$id('supplier')">Supplier</label>
                <select class="select" :id="$id('supplier')" x-model="supplierId" :aria-invalid="orderMessages().length > 0"
                        @change="errors = {}">
                    <option value="">Choose a supplier</option>
                    <template x-for="supplier in suppliers" :key="supplier.id">
                        <option :value="supplier.id" x-text="supplier.name"></option>
                    </template>
                </select>
                <template x-for="message in orderMessages()" :key="message">
                    <p class="inline-error" role="alert"><x-icon name="alert" /> <span x-text="message"></span></p>
                </template>
            </div>

            <h2 class="po-subtitle">Lines</h2>
            <p class="hint">Quantities start in kg and L. Switch to g or ml with the toggle. Lines can be changed until the order is sent.</p>

            <div class="stack">
                <template x-for="(line, index) in lines" :key="line.key">
                    <div class="po-line" x-id="['ingredient']">
                        <div class="field">
                            <label :for="$id('ingredient')">Ingredient</label>
                            <select class="select" :id="$id('ingredient')" x-model="line.ingredient_id" @change="ingredientChanged(line)">
                                <option value="">Choose an ingredient</option>
                                <template x-for="ingredient in ingredients" :key="ingredient.id">
                                    <option :value="ingredient.id" :disabled="taken(line, ingredient.id)"
                                            x-text="ingredient.name + ' (' + ingredient.unit + ')'"></option>
                                </template>
                            </select>
                        </div>

                        <div>
                            @include('pages.purchasing.quantity-by-unit', [
                                'model' => 'line.quantity',
                                'unit' => 'unitOf(line)',
                                'change' => 'line.mode = $event.detail.mode; line.error = $event.detail.error',
                                'label' => 'Quantity',
                            ])
                        </div>

                        <button type="button" class="btn btn-ghost btn-icon po-line-remove" aria-label="Remove this line"
                                :disabled="busy" @click="removeLine(index)">
                            <x-icon name="trash" />
                        </button>

                        <div class="po-line-errors">
                            <template x-for="message in lineMessages(index)" :key="message">
                                <p class="inline-error" role="alert"><x-icon name="alert" /> <span x-text="message"></span></p>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div>
                <button type="button" class="btn btn-secondary" :disabled="busy" @click="addLine()">
                    <x-icon name="plus" class="icon-sm" /> Add line
                </button>
            </div>
        </div>

        <div class="po-form-foot">
            <a class="btn btn-secondary" href="/purchase-orders">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">
                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                Save as draft
            </button>
        </div>
    </form>
</div>
@endsection
