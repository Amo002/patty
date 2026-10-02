{{--
    Dashboard (PTY-12): the first screen. Answers feature 6 at a glance: stock per ingredient (with what is
    still on its way) and the open purchase orders with what is outstanding, refreshed every 10 s.
    All data is rendered with x-text and attribute bindings, never as HTML (U12).
--}}
@extends('layouts.app')

@section('title', 'Dashboard')
@section('live', '1')

@push('scripts')
    @php
        $v = fn (string $path) => '/'.$path.'?v='.(is_file(public_path($path)) ? filemtime(public_path($path)) : '0');
    @endphp
    <link rel="stylesheet" href="{{ $v('css/pages/dashboard.css') }}">
    <script defer src="{{ $v('js/pages/live-list.js') }}"></script>
    <script defer src="{{ $v('js/pages/dashboard.js') }}"></script>
@endpush

@section('actions')
    {{-- Only when the guide was hidden: the way back to it. --}}
    <button type="button" class="btn btn-secondary" x-data x-show="$store.tour.dismissed" x-cloak @click="$store.tour.show()">
        <x-icon name="info" class="icon-sm" /> Show guide
    </button>
@endsection

@section('content')
<div class="stack dash" x-data="dashboard">

    {{-- Guided "Try it" card (U10). Each step ticks from real data; see public/js/pages/dashboard.js. --}}
    <section class="card tour" x-show="! $store.tour.dismissed" x-cloak aria-labelledby="tour-title">
        <div class="card-header">
            <div>
                <h2 id="tour-title">Try it: one delivery, one sale</h2>
                <span class="muted text-sm" x-text="$store.tour.doneCount() + ' of 5 done. Steps tick off by themselves.'"></span>
            </div>
            <button type="button" class="btn btn-ghost btn-icon" aria-label="Hide the guide" @click="$store.tour.hide()">
                <x-icon name="close" />
            </button>
        </div>
        <ol class="tour-steps">
            <template x-for="step in steps()" :key="step.n">
                <li class="tour-step" :data-done="step.done ? 'true' : null">
                    <span class="tour-mark" aria-hidden="true">
                        <x-icon name="check" class="icon-sm" x-show="step.done" />
                        <span x-show="! step.done" x-text="step.n"></span>
                    </span>
                    <div class="tour-text">
                        <a :href="step.href" x-text="step.title"></a>
                        <span class="text-sm muted" x-text="step.hint"></span>
                    </div>
                    <span class="visually-hidden" x-text="step.done ? 'Done' : 'Not done yet'"></span>
                </li>
            </template>
        </ol>
        <div class="tour-foot">
            <button type="button" class="btn btn-ghost" @click="$store.tour.restart()"><x-icon name="refresh" class="icon-sm" /> Restart the tour</button>
        </div>
    </section>

    {{-- KPI row (E28) --}}
    <div class="error-banner" role="alert" x-show="kpiError" x-cloak>
        <x-icon name="alert" />
        <span class="grow">
            <span x-text="kpiError && kpiError.message"></span>
            <span class="text-xs" x-show="kpiError && kpiError.requestId" x-text="'Request id: ' + (kpiError && kpiError.requestId)"></span>
        </span>
        <button type="button" class="btn btn-secondary" @click="retryKpis()"><x-icon name="refresh" class="icon-sm" /> Retry</button>
    </div>

    <div class="grid-kpi" :aria-busy="! kpis">
        <template x-for="n in (kpis ? 0 : 4)" :key="n">
            <div class="card kpi"><div class="skeleton skeleton-line w-50"></div><div class="skeleton skeleton-kpi"></div><div class="skeleton skeleton-line w-75"></div></div>
        </template>

        <template x-if="kpis">
            <div class="card kpi">
                <span class="kpi-label">Ingredients</span>
                <span class="kpi-value" x-flash="kpis.ingredients_count" x-text="kpis.ingredients_count"></span>
                <span class="kpi-note">in the catalogue</span>
            </div>
        </template>
        <template x-if="kpis">
            <div class="card kpi" :data-tone="kpis.negative_count > 0 ? 'danger' : null">
                <span class="kpi-label">Negative stock</span>
                <span class="kpi-value" x-flash="kpis.negative_count" x-text="kpis.negative_count"></span>
                <span class="kpi-note" x-text="kpis.negative_count > 0 ? 'Check counts and deliveries' : 'Every count is at or above zero'"></span>
            </div>
        </template>
        <template x-if="kpis">
            <div class="card kpi">
                <span class="kpi-label">Open orders</span>
                <span class="kpi-value" x-flash="kpis.open_orders_count" x-text="kpis.open_orders_count"></span>
                <span class="kpi-note">sent or part received</span>
            </div>
        </template>
        <template x-if="kpis">
            <div class="card kpi">
                <span class="kpi-label">Outstanding lines</span>
                <span class="kpi-value" x-flash="kpis.outstanding_lines_count" x-text="kpis.outstanding_lines_count"></span>
                <span class="kpi-note">still to arrive</span>
            </div>
        </template>
    </div>

    <div class="dash-grid">

        {{-- Stock (E27) --}}
        <section class="card" id="stock-panel" aria-labelledby="stock-title">
            <div class="card-header">
                <div>
                    <h2 id="stock-title">Stock</h2>
                    <span class="muted text-sm">Incoming is ordered and not yet delivered.</span>
                </div>
            </div>

            <div class="error-banner panel-error" role="alert" x-show="stock.error" x-cloak>
                <x-icon name="alert" />
                <span class="grow">
                    <span x-text="stock.error && stock.error.message"></span>
                    <span class="text-xs" x-show="stock.error && stock.error.requestId" x-text="'Request id: ' + (stock.error && stock.error.requestId)"></span>
                </span>
                <button type="button" class="btn btn-secondary" @click="retry(stock)"><x-icon name="refresh" class="icon-sm" /> Retry</button>
            </div>

            <div class="card-body stack" x-show="stock.loading && stock.rows.length === 0" aria-busy="true">
                <template x-for="n in 6" :key="n"><div class="skeleton skeleton-line"></div></template>
            </div>

            <div class="card-body" x-show="! stock.loading && ! stock.error && stock.rows.length === 0" x-cloak>
                <x-empty-state title="No ingredients yet" text="Add the things you cook with and stock will show here." icon="ingredients">
                    <a class="btn btn-primary" href="/ingredients"><x-icon name="plus" class="icon-sm" /> Add ingredient</a>
                </x-empty-state>
            </div>

            <div class="table-wrap" x-show="stock.rows.length > 0" x-cloak>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th class="num">On hand</th>
                            <th class="num">Incoming</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in stock.rows" :key="row.ingredient.id">
                            <tr :data-negative="row.is_negative">
                                <td x-text="row.ingredient.name"></td>
                                <td class="num">
                                    <span x-flash="row.on_hand" :class="row.is_negative ? 'text-danger' : ''"
                                          x-text="qty(row.on_hand, row.ingredient.unit).text" :title="qty(row.on_hand, row.ingredient.unit).exact"></span>
                                </td>
                                <td class="num">
                                    <span x-flash="row.incoming" :class="row.incoming === 0 ? 'muted' : ''"
                                          x-text="'+' + qty(row.incoming, row.ingredient.unit).text" :title="qty(row.incoming, row.ingredient.unit).exact"></span>
                                </td>
                                <td><span class="pill" :data-tone="stockStatus(row).tone" x-text="stockStatus(row).label"></span></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="list-end" x-show="stock.hasMore" x-cloak x-init="Patty.watchEnd($el, stock)">
                <button type="button" class="btn btn-secondary" :disabled="stock.loadingMore" :aria-busy="stock.loadingMore" @click="stock.more().catch(() => {})">
                    <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span> Load more
                </button>
            </div>
        </section>

        {{-- Open orders (E16, status=open). Loads when scrolled near (U2). --}}
        <section class="card" id="orders-panel" aria-labelledby="orders-title" x-init="watchOrders($el)">
            <div class="card-header">
                <div>
                    <h2 id="orders-title">Open orders</h2>
                    <span class="muted text-sm">Sent or part received, with what is still outstanding.</span>
                </div>
                <a class="btn btn-secondary" href="/purchase-orders">All orders</a>
            </div>

            <div class="error-banner panel-error" role="alert" x-show="orders.error" x-cloak>
                <x-icon name="alert" />
                <span class="grow">
                    <span x-text="orders.error && orders.error.message"></span>
                    <span class="text-xs" x-show="orders.error && orders.error.requestId" x-text="'Request id: ' + (orders.error && orders.error.requestId)"></span>
                </span>
                <button type="button" class="btn btn-secondary" @click="retry(orders)"><x-icon name="refresh" class="icon-sm" /> Retry</button>
            </div>

            <div class="card-body stack" x-show="orders.loading && orders.rows.length === 0" aria-busy="true">
                <template x-for="n in 4" :key="n"><div class="skeleton skeleton-line"></div></template>
            </div>

            <div class="card-body" x-show="! orders.loading && ! orders.error && orders.started && orders.rows.length === 0" x-cloak>
                <x-empty-state title="No open orders" text="Everything ordered has arrived, or nothing has been sent yet." icon="purchase-order">
                    <a class="btn btn-primary" href="/purchase-orders/new"><x-icon name="plus" class="icon-sm" /> Create one</a>
                </x-empty-state>
            </div>

            <div class="table-wrap" x-show="orders.rows.length > 0" x-cloak>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th>Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="order in orders.rows" :key="order.id">
                            <tr>
                                <td>
                                    <a :href="orderHref(order)" x-text="order.number"></a>
                                    <div class="text-xs muted" x-text="order.supplier.name"></div>
                                </td>
                                <td><span class="pill" :data-tone="meta(order).tone" x-text="meta(order).label"></span></td>
                                <td>
                                    <div class="row progress-cell">
                                        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="Received"
                                             :aria-valuenow="pct(order)" :data-complete="pct(order) === 100 ? 'true' : null"
                                             :style="'--value: ' + pct(order) + '%'"><span></span></div>
                                        <span class="num text-sm" x-text="pct(order) + '%'"></span>
                                    </div>
                                </td>
                                <td class="outstanding" x-text="outstanding(order)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="list-end" x-show="orders.hasMore" x-cloak x-init="Patty.watchEnd($el, orders)">
                <button type="button" class="btn btn-secondary" :disabled="orders.loadingMore" :aria-busy="orders.loadingMore" @click="orders.more().catch(() => {})">
                    <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span> Load more
                </button>
            </div>
        </section>
    </div>
</div>
@endsection
