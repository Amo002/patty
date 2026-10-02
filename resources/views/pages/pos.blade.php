@extends('layouts.app')

@section('title', 'POS Simulator')
@section('live', '1')

@push('scripts')
    @include('pages.purchasing.head', ['script' => 'pos'])
@endpush

@section('content')
<div class="stack" x-data="posSimulator">

    <p class="muted">Plays the till. Each sale is sent to the API as the POS would send it (channel "pos") and takes its ingredients out of stock.</p>

    <div class="grid-2">
        {{-- The till --}}
        <section class="card">
            <div class="card-header"><h2>Sell</h2></div>
            <div class="card-body stack">
                <div class="error-banner" role="alert" x-show="loadError" x-cloak>
                    <x-icon name="alert" />
                    <span class="grow">
                        <span x-text="loadError && loadError.message"></span>
                        <span class="text-xs" x-show="loadError && loadError.requestId" x-text="'Request id: ' + (loadError && loadError.requestId)"></span>
                    </span>
                    <button type="button" class="btn btn-secondary" @click="loadItems()"><x-icon name="refresh" class="icon-sm" /> Retry</button>
                </div>

                <div class="stack" x-show="loading" aria-busy="true">
                    <div class="skeleton skeleton-line w-25"></div>
                    <div class="skeleton skeleton-line"></div>
                    <div class="skeleton skeleton-line w-50"></div>
                </div>

                <div x-show="! loading && ! loadError && items.length === 0" x-cloak>
                    <x-empty-state title="No menu items yet" text="Create a menu item with a recipe to sell it here." icon="menu">
                        <a class="btn btn-primary" href="/menu"><x-icon name="plus" class="icon-sm" /> Go to the menu</a>
                    </x-empty-state>
                </div>

                <form class="stack" novalidate x-show="! loading && ! loadError && items.length > 0" x-cloak @submit.prevent="sell()">
                    <div class="field" x-id="['item']">
                        <label :for="$id('item')">Menu item</label>
                        <select class="select" :id="$id('item')" x-model="menuItemId" @change="quantityError = ''">
                            <template x-for="item in items" :key="item.id">
                                <option :value="item.id" :disabled="! item.is_sellable"
                                        x-text="item.is_sellable ? item.name : item.name + ' (No recipe yet)'"></option>
                            </template>
                        </select>
                    </div>

                    <div class="field" x-id="['qty']">
                        <label :for="$id('qty')">Quantity</label>
                        <input class="input pos-qty" type="number" min="1" max="1000" step="1" inputmode="numeric"
                               :id="$id('qty')" x-model.number="quantity" :aria-invalid="quantityError !== ''"
                               :aria-describedby="$id('qty') + '-msg'">
                        <div :id="$id('qty') + '-msg'">
                            <p class="inline-error" role="alert" x-show="quantityError"><x-icon name="alert" /> <span x-text="quantityError"></span></p>
                        </div>
                    </div>

                    <div class="pos-preview" x-show="preview().length > 0" x-cloak>
                        <span class="text-sm muted">This sale takes out</span>
                        <ul>
                            <template x-for="line in preview()" :key="line">
                                <li class="text-sm" x-text="line"></li>
                            </template>
                        </ul>
                    </div>

                    <div class="row">
                        <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">
                            <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                            Sell
                        </button>
                    </div>

                    <div class="stack stack-sm pos-retry" x-show="last" x-cloak>
                        <span class="text-sm muted">Retry protection. The till sent reference <span class="mono" x-text="last && last.pos_reference"></span>.</span>
                        <div class="row">
                            <button type="button" class="btn btn-secondary" :disabled="busy" :aria-busy="busy" @click="resend()">Resend last sale</button>
                            <button type="button" class="btn btn-secondary" :disabled="busy || ! canResendChanged()" :aria-busy="busy" @click="resendChanged()">
                                Resend with quantity +1
                            </button>
                        </div>
                        <p class="hint">An exact resend is a replay: stock does not move again. A changed resend under the same reference is refused.</p>
                    </div>
                </form>
            </div>
        </section>

        {{-- The answer --}}
        <section class="card">
            <div class="card-header"><h2>Result</h2></div>
            <div class="card-body stack">
                <div class="pos-notice" role="alert" x-show="notice" x-cloak :data-tone="notice && notice.tone">
                    <x-icon name="alert" />
                    <div class="stack stack-sm">
                        <strong x-text="notice && notice.title"></strong>
                        <p class="text-sm" x-text="notice && notice.text"></p>
                        <p class="text-xs muted" x-show="notice && notice.detail" x-text="notice && notice.detail"></p>
                    </div>
                </div>

                <p class="muted" x-show="! result && ! notice">Sell something to see what it did to stock.</p>

                <div class="stack" x-show="result" x-cloak>
                    <template x-if="result">
                        <div class="stack">
                            <div class="row">
                                <strong x-text="result.number"></strong>
                                <span class="text-sm muted" x-text="result.menu_item.name + ' x ' + result.quantity"></span>
                                <span class="pill" data-tone="info" x-show="result.replayed">Replayed, stock not moved again</span>
                                <span class="pill" data-tone="ok" x-show="! result.replayed">Recorded</span>
                            </div>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead>
                                        <tr><th>Ingredient</th><th class="num">Deducted</th><th class="num">On hand after</th><th>Status</th></tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="d in result.deductions" :key="d.ingredient.id">
                                            <tr :data-negative="d.is_negative">
                                                <td x-text="d.ingredient.name"></td>
                                                <td class="num"><span :title="qtyExact(d.quantity, d.ingredient.unit)" x-text="qty(d.quantity, d.ingredient.unit)"></span></td>
                                                <td class="num"><span :class="d.is_negative ? 'text-danger' : ''" :title="qtyExact(d.on_hand_after, d.ingredient.unit)" x-text="qty(d.on_hand_after, d.ingredient.unit)"></span></td>
                                                <td><span class="pill" data-tone="danger" x-show="d.is_negative">Negative</span></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <p class="hint" x-show="result.deductions.some(function (d) { return d.is_negative; })">
                                A negative balance means the count was wrong or a delivery was not recorded. The sale still went through, because it already happened.
                            </p>
                        </div>
                    </template>
                </div>
            </div>
        </section>
    </div>

    {{-- Recent sales --}}
    <section class="card">
        <div class="card-header"><h2>Recent sales</h2></div>

        <div class="card-body" x-show="recentError" x-cloak>
            <div class="error-banner" role="alert">
                <x-icon name="alert" />
                <span class="grow">
                    <span x-text="recentError && recentError.message"></span>
                    <span class="text-xs" x-show="recentError && recentError.requestId" x-text="'Request id: ' + (recentError && recentError.requestId)"></span>
                </span>
                <button type="button" class="btn btn-secondary" @click="loadRecent()"><x-icon name="refresh" class="icon-sm" /> Retry</button>
            </div>
        </div>

        <div class="card-body stack" x-show="recentLoading" aria-busy="true">
            <template x-for="n in 4" :key="n"><div class="skeleton skeleton-line"></div></template>
        </div>

        <div x-show="! recentLoading && ! recentError && recent.length === 0" x-cloak>
            <x-empty-state title="No sales yet" text="Press Sell above and the sale shows up here." icon="pos" />
        </div>

        <div class="table-wrap" x-show="! recentLoading && recent.length > 0" x-cloak>
            <table class="table">
                <thead>
                    <tr><th>Sale</th><th>Item</th><th class="num">Quantity</th><th>Reference</th><th>Sold</th></tr>
                </thead>
                <tbody>
                    <template x-for="sale in recent" :key="sale.id">
                        <tr>
                            <td class="po-number" x-text="sale.number"></td>
                            <td x-text="sale.menu_item.name"></td>
                            <td class="num" x-text="sale.quantity"></td>
                            <td class="mono" x-text="sale.pos_reference"></td>
                            <td class="text-sm muted" :title="when(sale.sold_at)" x-text="ago(sale.sold_at)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="row po-more" x-ref="sentinel">
            <button type="button" class="btn btn-secondary" x-show="recentHasMore" x-cloak :disabled="recentLoadingMore" :aria-busy="recentLoadingMore" @click="loadMoreRecent()">
                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                Load more
            </button>
        </div>
    </section>
</div>
@endsection
