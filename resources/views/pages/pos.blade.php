@extends('layouts.app')

@section('title', 'POS Simulator')
@section('live', '1')

@push('scripts')
    @include('pages.purchasing.head', ['script' => 'pos'])
@endpush

@section('content')
<div class="stack" x-data="posSimulator">

    <p class="muted">Plays the till. Build a ticket of one or more items; it is sent to the API as the POS would send it (channel "pos") and takes its ingredients out of stock.</p>

    <div class="grid-2">
        {{-- The till --}}
        <section class="card">
            <div class="card-header"><h2>Ticket</h2></div>
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

                <div class="stack" x-show="! loading && ! loadError && items.length > 0" x-cloak>
                    {{-- Tiles: one tap adds one of that item to the ticket. --}}
                    <div class="pos-tiles">
                        <template x-for="item in items" :key="item.id">
                            <button type="button" class="pos-tile" :disabled="busy || ! item.is_sellable" @click="add(item)"
                                    :aria-label="item.is_sellable ? 'Add ' + item.name + ' to the ticket' : item.name + ', no recipe yet'">
                                <x-icon name="menu" />
                                <strong x-text="item.name"></strong>
                                <span class="text-sm muted" x-text="item.is_sellable ? plural(item.recipe.length, 'ingredient') : 'No recipe yet'"></span>
                                <span class="pos-tile-badge" x-show="quantityOnTicket(item.id) > 0" x-text="quantityOnTicket(item.id)" aria-hidden="true"></span>
                            </button>
                        </template>
                    </div>

                    <form class="pos-ticket" novalidate @submit.prevent="sell()" aria-label="Current ticket">
                        <div class="pos-ticket-head">
                            <strong>Current ticket</strong>
                            <span class="text-sm muted" aria-live="polite" x-text="plural(itemCount(), 'item') + ' · ' + plural(unitCount(), 'unit')"></span>
                        </div>

                        <p class="pos-ticket-empty muted" x-show="ticket.length === 0">Tap a menu item to start the ticket.</p>

                        <div>
                            <template x-for="(line, index) in ticket" :key="line.menu_item_id">
                                <div class="pos-line" :data-invalid="line.error !== ''" x-id="['qty']" role="group" :aria-label="itemName(line)">
                                    <div class="stack stack-sm">
                                        <strong x-text="itemName(line)"></strong>
                                        <span class="text-sm muted" x-text="recipeHint(line)"></span>
                                    </div>
                                    <div class="pos-stepper">
                                        <button type="button" class="btn btn-secondary btn-icon" :aria-label="'One less ' + itemName(line)" :disabled="busy" @click="step(index, -1)">&minus;</button>
                                        <label class="visually-hidden" :for="$id('qty')" x-text="'Quantity of ' + itemName(line)"></label>
                                        <input class="input pos-qty" type="number" min="1" max="1000" step="1" inputmode="numeric"
                                               :id="$id('qty')" :value="line.quantity" :disabled="busy" :aria-invalid="line.error !== ''"
                                               :aria-describedby="$id('qty') + '-msg'" @change="setQuantity(index, $event.target.value); $event.target.value = line.quantity">
                                        <button type="button" class="btn btn-secondary btn-icon" :aria-label="'One more ' + itemName(line)" :disabled="busy || line.quantity >= 1000" @click="step(index, 1)">
                                            <x-icon name="plus" class="icon-sm" />
                                        </button>
                                    </div>
                                    <button type="button" class="btn btn-ghost btn-icon" :aria-label="'Remove ' + itemName(line)" :disabled="busy" @click="remove(index)">
                                        <x-icon name="trash" class="icon-sm" />
                                    </button>
                                    <div class="pos-line-error" :id="$id('qty') + '-msg'">
                                        <p class="inline-error" role="alert" x-show="line.error"><x-icon name="alert" /> <span x-text="line.error"></span></p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="pos-ticket-foot">
                            <button type="button" class="btn btn-ghost" :disabled="busy || ticket.length === 0" @click="clearTicket()">Clear ticket</button>
                            <button type="submit" class="btn btn-primary" :disabled="busy || ticket.length === 0" :aria-busy="busy">
                                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                                Sell ticket
                            </button>
                        </div>
                    </form>

                    <div class="pos-preview" x-show="preview().length > 0" x-cloak>
                        <span class="text-sm muted">This ticket takes out</span>
                        <ul>
                            <template x-for="row in preview()" :key="row.id">
                                <li class="text-sm">
                                    <span>
                                        <span x-text="row.ingredient.name"></span>
                                        <span class="text-xs muted" x-show="row.sourceText" x-text="row.sourceText"></span>
                                    </span>
                                    <span class="num" :title="qtyExact(row.total, row.ingredient.unit)" x-text="'-' + qty(row.total, row.ingredient.unit)"></span>
                                    <span class="num" x-show="row.after !== null">
                                        <span :class="row.after < 0 ? 'text-danger' : 'muted'"
                                              :title="qtyExact(row.after, row.ingredient.unit)"
                                              x-text="row.after < 0 ? 'goes to -' + qty(-row.after, row.ingredient.unit) : 'leaves ' + qty(row.after, row.ingredient.unit)"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div class="stack stack-sm pos-retry" x-show="last" x-cloak>
                        <span class="text-sm muted">Retry protection. The till sent reference <span class="mono" x-text="last && last.pos_reference"></span>.</span>
                        <div class="row">
                            <button type="button" class="btn btn-secondary" :disabled="busy" :aria-busy="busy" @click="resend()">Resend last ticket</button>
                            <button type="button" class="btn btn-secondary" :disabled="busy || ! canResendChanged()" :aria-busy="busy" @click="resendChanged()">
                                Resend with one more of the first item
                            </button>
                        </div>
                        <p class="hint">An exact resend is a replay: stock does not move again. A changed resend under the same reference is refused.</p>
                    </div>
                </div>
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
                        <p class="text-xs muted" x-show="notice && notice.requestId" x-text="'Request id: ' + (notice && notice.requestId)"></p>
                    </div>
                </div>

                <p class="muted" x-show="! result && ! notice">Sell something to see what it did to stock.</p>

                <div class="stack" x-show="result" x-cloak>
                    <template x-if="result">
                        <div class="stack">
                            <div class="row">
                                <strong x-text="result.number"></strong>
                                <span class="text-sm muted" x-text="plural(result.item_count, 'item')"></span>
                                <span class="pill" data-tone="info" x-show="result.replayed">Replayed, stock not moved again</span>
                                <span class="pill" data-tone="ok" x-show="! result.replayed">Recorded</span>
                            </div>
                            <div class="row">
                                <template x-for="line in result.lines" :key="line.menu_item.id">
                                    <span class="pill" x-text="line.menu_item.name + ' x' + line.quantity"></span>
                                </template>
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
        <div class="card-header"><h2 tabindex="-1" x-ref="recentHeading">Recent sales</h2></div>

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
            <x-empty-state title="No sales yet" text="Press Sell ticket above and the sale shows up here." icon="pos" />
        </div>

        <div class="table-wrap" x-show="! recentLoading && recent.length > 0" x-cloak>
            <table class="table">
                <thead>
                    <tr><th>Sale</th><th>Items</th><th class="num">Units</th><th>Reference</th><th>Sold</th><th><span class="visually-hidden">Refund</span></th></tr>
                </thead>
                <tbody>
                    <template x-for="sale in recent" :key="sale.id">
                        <tr>
                            <td class="po-number" x-text="sale.number"></td>
                            <td>
                                <span x-text="sale.lines.map(function (l) { return l.menu_item.name + ' x' + l.quantity; }).join(', ')"></span>
                                <span class="pos-refunded" x-show="refundedPills(sale).length > 0">
                                    <template x-for="p in refundedPills(sale)" :key="p.id">
                                        <span class="pill" data-tone="warn" x-text="p.text"></span>
                                    </template>
                                </span>
                            </td>
                            <td class="num" x-text="sale.units"></td>
                            <td class="mono" x-text="sale.pos_reference"></td>
                            <td class="text-sm muted" :title="when(sale.sold_at)" x-text="ago(sale.sold_at)"></td>
                            <td>
                                <button type="button" class="btn btn-secondary" :disabled="! canRefund(sale)"
                                        :aria-label="(canRefund(sale) ? 'Refund ' : 'Fully refunded: ') + sale.number"
                                        @click="openRefund(sale)" x-text="canRefund(sale) ? 'Refund' : 'Refunded'"></button>
                            </td>
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

    {{-- Refund panel (E33). A native dialog like the receive one: Escape closes it, focus stays inside, no extra script. --}}
    <dialog class="dialog dialog-wide" x-ref="refundDialog" aria-labelledby="refund-title" @cancel="refund.busy && $event.preventDefault()" @close="refundClosed()">
        <form novalidate @submit.prevent="refundSale()" x-show="refund.sale">
            <div class="dialog-head">
                <h2 id="refund-title" x-text="'Refund from ' + (refund.sale && refund.sale.number)"></h2>
                <p class="hint" x-text="refund.sale && (refund.sale.lines.map(function (l) { return l.menu_item.name + ' x' + l.quantity; }).join(', ') + ' · reference ' + refund.sale.pos_reference)"></p>
            </div>

            <div class="dialog-body stack">
                {{-- 1. What --}}
                <fieldset class="rf-group" :disabled="refund.busy">
                    <legend>What is being refunded?</legend>
                    <template x-for="line in refund.lines" :key="line.menu_item_id">
                        <div class="rf-line" :data-invalid="line.error !== ''" x-id="['rqty']" role="group" :aria-label="line.name">
                            <div class="stack stack-sm">
                                <strong class="text" x-text="line.name"></strong>
                                <span class="text-sm muted" x-text="'sold ' + line.sold + ' · already refunded ' + line.refunded + ' · ' + line.left + ' left to refund'"></span>
                            </div>
                            <span class="pill" data-tone="neutral" x-show="line.left === 0">Fully refunded</span>
                            <div class="pos-stepper" x-show="line.left > 0">
                                <button type="button" class="btn btn-secondary btn-icon" :aria-label="'One less ' + line.name" :disabled="refund.busy || line.quantity <= 0" @click="refundStep(line, -1)">&minus;</button>
                                <label class="visually-hidden" :for="$id('rqty')" x-text="'Quantity of ' + line.name + ' to refund'"></label>
                                <input class="input pos-qty" type="number" min="0" :max="line.left" step="1" inputmode="numeric"
                                       :id="$id('rqty')" :value="line.quantity" :disabled="refund.busy" :aria-invalid="line.error !== ''"
                                       :aria-describedby="$id('rqty') + '-msg'" @change="refundSet(line, $event.target.value); $event.target.value = line.quantity">
                                <button type="button" class="btn btn-secondary btn-icon" :aria-label="'One more ' + line.name" :disabled="refund.busy || line.quantity >= line.left" @click="refundStep(line, 1)">
                                    <x-icon name="plus" class="icon-sm" />
                                </button>
                            </div>
                            <div class="rf-line-error" :id="$id('rqty') + '-msg'">
                                <p class="inline-error" role="alert" x-show="line.error"><x-icon name="alert" /> <span x-text="line.error"></span></p>
                            </div>
                        </div>
                    </template>
                </fieldset>

                {{-- 2. Was the food made? Restock is required by the API, so the choice is explicit; "made" (no stock change) is preselected. --}}
                <fieldset class="rf-group" :disabled="refund.busy">
                    <legend>Was the food already made?</legend>
                    <div class="rf-choices">
                        <label class="rf-choice">
                            <input type="radio" name="refund-restock" :checked="! refund.restock" @change="refund.restock = false; clearRefundFeedback()">
                            <span class="stack stack-sm">
                                <strong class="text">Yes, it was made</strong>
                                <span class="text-sm muted">Cooked and served or thrown away. The ingredients were really used, so stock does not change.</span>
                            </span>
                        </label>
                        <label class="rf-choice">
                            <input type="radio" name="refund-restock" :checked="refund.restock" @change="refund.restock = true; clearRefundFeedback()">
                            <span class="stack stack-sm">
                                <strong class="text">No, voided before cooking</strong>
                                <span class="text-sm muted">Nothing left the fridge. The ingredients go back to stock.</span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                {{-- 3. Why --}}
                <div class="field" x-id="['reason']">
                    <label :for="$id('reason')">Reason</label>
                    <select class="select" :id="$id('reason')" x-model="refund.reason" :disabled="refund.busy" @change="clearRefundFeedback()">
                        <template x-for="r in reasons" :key="r"><option :value="r" x-text="r"></option></template>
                    </select>
                </div>

                {{-- What it will do --}}
                <div class="pos-preview">
                    <strong class="text text-sm">What happens to stock</strong>
                    <p class="text-sm muted" x-show="refundUnits() === 0">Choose at least one item to refund.</p>
                    <p class="hint" x-show="refundUnits() > 0">Estimated from today's recipe. The refund puts back exactly what this sale took.</p>
                    <template x-if="refundUnits() > 0 && refund.restock">
                        <div>
                            <ul>
                                <template x-for="row in refundPreview()" :key="row.id">
                                    <li class="text-sm">
                                        <span x-text="row.ingredient.name"></span>
                                        <span class="num text-ok" :title="qtyExact(row.total, row.ingredient.unit)" x-text="'+' + qty(row.total, row.ingredient.unit)"></span>
                                        <span class="num muted" x-show="row.after !== null" :title="qtyExact(row.after, row.ingredient.unit)" x-text="'back to ' + qty(row.after, row.ingredient.unit)"></span>
                                    </li>
                                </template>
                            </ul>
                            <p class="hint">One new movement per ingredient. The original sale movements stay as they are.</p>
                        </div>
                    </template>
                    <template x-if="refundUnits() > 0 && ! refund.restock">
                        <div class="stack stack-sm">
                            <p class="text-sm"><strong class="text">No stock change.</strong></p>
                            <p class="text-sm muted" x-show="refundUsedText() !== ''" x-text="'The food was made, so ' + refundUsedText() + ' really left the kitchen. The refund is recorded for the sales report and the audit trail only.'"></p>
                            <p class="text-sm muted" x-show="refundUsedText() === ''">The refund is recorded for the sales report and the audit trail only.</p>
                        </div>
                    </template>
                </div>

                {{-- Outcome. The status element is the only live region, so a success is announced once; errors use role=alert. --}}
                <p class="rf-done text-sm" role="status" x-show="refund.done">
                    <template x-if="refund.done"><span><strong class="text" x-text="refund.done.title"></strong> <span x-text="refund.done.text"></span></span></template>
                </p>
                <div class="pos-notice" role="alert" x-show="refund.notice" x-cloak :data-tone="refund.notice && refund.notice.tone">
                    <x-icon name="alert" />
                    <div class="stack stack-sm">
                        <strong x-text="refund.notice && refund.notice.title"></strong>
                        <p class="text-sm" x-text="refund.notice && refund.notice.text"></p>
                        <p class="text-xs muted" x-show="refund.notice && refund.notice.detail" x-text="refund.notice && refund.notice.detail"></p>
                        <p class="text-xs muted" x-show="refund.notice && refund.notice.requestId" x-text="'Request id: ' + (refund.notice && refund.notice.requestId)"></p>
                    </div>
                </div>
            </div>

            <div class="dialog-foot">
                <button type="button" class="btn btn-secondary" :disabled="refund.busy" @click="closeRefund()">Close</button>
                <button type="submit" class="btn btn-primary" :disabled="refund.busy || refundUnits() === 0" :aria-busy="refund.busy">
                    <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                    <span x-text="refundLabel()"></span>
                </button>
            </div>
        </form>
    </dialog>
</div>
@endsection
