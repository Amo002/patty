@extends('layouts.app')

@section('title', 'Purchase Orders')
@section('live', '1')

@push('scripts')
    @include('pages.purchasing.head', ['script' => 'purchase-orders'])
@endpush

@section('actions')
    <a class="btn btn-primary" href="/purchase-orders/new"><x-icon name="plus" class="icon-sm" /> New order</a>
@endsection

@section('content')
<div class="stack" x-data="purchaseOrders">

    <div class="segmented po-tabs" role="group" aria-label="Filter by status">
        <template x-for="tab in tabs" :key="tab.value">
            <button type="button" :aria-pressed="status === tab.value" @click="setStatus(tab.value)" x-text="tab.label"></button>
        </template>
    </div>

    {{-- Error: message, request id, retry (U4). --}}
    <div class="error-banner" role="alert" x-show="error" x-cloak>
        <x-icon name="alert" />
        <span class="grow">
            <span x-text="error && error.message"></span>
            <span class="text-xs" x-show="error && error.requestId" x-text="'Request id: ' + (error && error.requestId)"></span>
        </span>
        <button type="button" class="btn btn-secondary" @click="load()"><x-icon name="refresh" class="icon-sm" /> Retry</button>
    </div>

    {{-- Loading: placeholders shaped like the rows. --}}
    <div class="card" x-show="loading" aria-busy="true">
        <div class="card-body stack">
            <template x-for="n in 5" :key="n">
                <div class="skeleton skeleton-line"></div>
            </template>
        </div>
    </div>

    {{-- Empty: the next action is one click away. --}}
    <div x-show="! loading && ! error && items.length === 0" x-cloak>
        <div x-show="! emptyFiltered()">
            <x-empty-state title="No purchase orders yet" text="Draft an order to a supplier to start stocking up." icon="purchase-order">
                <a class="btn btn-primary" href="/purchase-orders/new"><x-icon name="plus" class="icon-sm" /> New order</a>
            </x-empty-state>
        </div>
        <div x-show="emptyFiltered()">
            <x-empty-state title="No orders with this status" text="Try another tab, or draft a new order." icon="purchase-order">
                <a class="btn btn-primary" href="/purchase-orders/new"><x-icon name="plus" class="icon-sm" /> New order</a>
            </x-empty-state>
        </div>
    </div>

    <div class="card" x-show="! loading && items.length > 0" x-cloak>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th class="po-progress-col">Received</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="po in items" :key="po.id">
                        <tr>
                            <td><a class="po-number" :href="'/purchase-orders/' + encodeURIComponent(po.id)" x-text="po.number"></a></td>
                            <td x-text="po.supplier.name"></td>
                            <td><span class="pill" :data-tone="tone(po)" x-text="label(po)"></span></td>
                            <td>
                                <div class="row po-progress">
                                    <div class="progress grow" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                                         :aria-valuenow="po.progress_percent" aria-label="Received"
                                         :data-complete="po.progress_percent === 100" :style="'--value:' + po.progress_percent + '%'"><span></span></div>
                                    <span class="num text-sm" x-text="po.progress_percent + '%'"></span>
                                </div>
                            </td>
                            <td class="text-sm muted" :title="when(po.created_at)" x-text="ago(po.created_at)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Lazy loading: the observer watches this element; the button is the keyboard fallback (U2). --}}
    <div class="row po-more" x-ref="sentinel">
        <button type="button" class="btn btn-secondary" x-show="hasMore" x-cloak :disabled="loadingMore" :aria-busy="loadingMore" @click="loadMore()">
            <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
            Load more
        </button>
    </div>
</div>
@endsection
