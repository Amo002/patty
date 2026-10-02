@extends('layouts.app')

@section('title', 'Purchase order')
@section('live', '1')

@push('scripts')
    @include('pages.purchasing.head', ['script' => 'purchase-order'])
@endpush

@section('actions')
    <a class="btn btn-secondary" href="/purchase-orders">Back to orders</a>
@endsection

@section('content')
<div class="stack" x-data="purchaseOrder(@js($ulid))">

    {{-- Loading --}}
    <div class="card" x-show="loading" aria-busy="true">
        <div class="card-body stack">
            <div class="skeleton skeleton-line w-25"></div>
            <div class="skeleton skeleton-line w-75"></div>
            <div class="skeleton skeleton-block"></div>
        </div>
    </div>

    {{-- A 404 from the API: unknown or deleted order --}}
    <div x-show="notFound" x-cloak>
        <x-empty-state title="Purchase order not found" text="It may have been deleted, or the link is wrong." icon="purchase-order">
            <a class="btn btn-primary" href="/purchase-orders">Back to purchase orders</a>
        </x-empty-state>
    </div>

    {{-- Any other failure --}}
    <div class="error-banner" role="alert" x-show="error" x-cloak>
        <x-icon name="alert" />
        <span class="grow">
            <span x-text="error && error.message"></span>
            <span class="text-xs" x-show="error && error.requestId" x-text="'Request id: ' + (error && error.requestId)"></span>
        </span>
        <button type="button" class="btn btn-secondary" @click="load()"><x-icon name="refresh" class="icon-sm" /> Retry</button>
    </div>

    <template x-if="po">
        <div class="stack">

            {{-- Header --}}
            <section class="card">
                <div class="card-header">
                    <div class="stack stack-sm">
                        <div class="row">
                            <h2 class="po-heading" x-text="po.number"></h2>
                            <span class="pill" :data-tone="tone()" x-text="label()"></span>
                        </div>
                        <p class="muted text-sm">
                            <span>Supplier: <strong class="text" x-text="po.supplier.name"></strong></span>
                            <span x-show="po.sent_at"> &middot; Sent <span :title="when(po.sent_at)" x-text="ago(po.sent_at)"></span></span>
                            <span x-show="po.closed_at"> &middot; Closed <span :title="when(po.closed_at)" x-text="ago(po.closed_at)"></span></span>
                        </p>
                    </div>

                    {{-- Only what the server allows (U5). While editing, the editor has its own buttons. --}}
                    <div class="row" x-show="! editing">
                        <button type="button" class="btn btn-secondary" x-show="can('edit_lines')" @click="startEdit()">
                            <x-icon name="edit" class="icon-sm" /> Edit lines
                        </button>
                        <button type="button" class="btn btn-danger" x-show="can('delete')" @click="remove()">
                            <x-icon name="trash" class="icon-sm" /> Delete draft
                        </button>
                        <button type="button" class="btn btn-secondary" x-show="can('short_close')" @click="shortClose()">Close short</button>
                        <button type="button" class="btn btn-secondary" x-show="can('send')" @click="send()">Send order</button>
                        <button type="button" class="btn btn-primary" x-show="can('receive')" @click="openReceive()">
                            <x-icon name="plus" class="icon-sm" /> Receive delivery
                        </button>
                    </div>
                </div>

                <div class="card-body">
                    <div class="row po-progress-row">
                        <span class="text-sm muted">Received</span>
                        <div class="progress grow" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                             :aria-valuenow="po.progress_percent" aria-label="Received"
                             :data-complete="po.progress_percent === 100" :style="'--value:' + po.progress_percent + '%'"><span></span></div>
                        <span class="num text-sm" x-flash="po.progress_percent" x-text="po.progress_percent + '%'"></span>
                    </div>
                </div>
            </section>

            {{-- Lines, read-only --}}
            <section class="card" x-show="! editing">
                <div class="card-header"><h2>Lines</h2></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th class="num">Ordered</th>
                                <th class="num">Received</th>
                                <th class="num">Outstanding</th>
                                <th>Limits</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="line in po.lines" :key="line.id">
                                <tr>
                                    <td x-text="line.ingredient.name"></td>
                                    <td class="num"><span :title="qtyExact(line.quantity_ordered, line.ingredient.unit)" x-text="qty(line.quantity_ordered, line.ingredient.unit)"></span></td>
                                    <td class="num"><span x-flash="line.quantity_received" :title="qtyExact(line.quantity_received, line.ingredient.unit)" x-text="qty(line.quantity_received, line.ingredient.unit)"></span></td>
                                    <td class="num"><span x-flash="line.quantity_outstanding" :title="qtyExact(line.quantity_outstanding, line.ingredient.unit)" x-text="qty(line.quantity_outstanding, line.ingredient.unit)"></span></td>
                                    <td class="text-sm muted po-limits">
                                        <span :title="qtyExact(line.max_receivable, line.ingredient.unit)" x-text="'Up to ' + qty(line.max_receivable, line.ingredient.unit)"></span>
                                        <span :title="qtyExact(line.min_to_complete, line.ingredient.unit)" x-text="'Complete at ' + qty(line.min_to_complete, line.ingredient.unit)"></span>
                                    </td>
                                    <td>
                                        <div class="row po-tags">
                                            <template x-for="tag in tags(line)" :key="tag.text">
                                                <span class="pill" :data-tone="tag.tone" x-text="tag.text"></span>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Lines, editing (draft only) --}}
            <form class="card" novalidate x-show="editing" x-cloak @submit.prevent="saveEdit()">
                <div class="card-header"><h2>Edit lines</h2></div>
                <div class="card-body stack">
                    <template x-for="message in editOrderMessages()" :key="message">
                        <p class="inline-error" role="alert"><x-icon name="alert" /> <span x-text="message"></span></p>
                    </template>
                    <p class="hint">Quantities start in kg and L. Switch to g or ml with the toggle.</p>

                    <template x-for="(line, index) in edit.lines" :key="line.key">
                        <div class="po-line" x-id="['ingredient']">
                            <div class="field">
                                <label :for="$id('ingredient')">Ingredient</label>
                                <select class="select" :id="$id('ingredient')" x-model="line.ingredient_id" @change="editIngredientChanged(line)">
                                    <option value="">Choose an ingredient</option>
                                    <template x-for="ingredient in ingredients" :key="ingredient.id">
                                        <option :value="ingredient.id" :disabled="taken(line, ingredient.id)"
                                                :selected="ingredient.id === line.ingredient_id"
                                                x-text="ingredient.name + ' (' + ingredient.unit + ')'"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                @include('pages.purchasing.quantity-by-unit', [
                                    'model' => 'line.quantity',
                                    'unit' => 'line.unit',
                                    'change' => 'line.mode = $event.detail.mode; line.error = $event.detail.error',
                                    'label' => 'Quantity',
                                ])
                            </div>
                            <button type="button" class="btn btn-ghost btn-icon po-line-remove" aria-label="Remove this line"
                                    :disabled="edit.busy" @click="removeEditLine(index)">
                                <x-icon name="trash" />
                            </button>
                            <div class="po-line-errors">
                                <template x-for="message in editMessages(index)" :key="message">
                                    <p class="inline-error" role="alert"><x-icon name="alert" /> <span x-text="message"></span></p>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div>
                        <button type="button" class="btn btn-secondary" :disabled="edit.busy" @click="addEditLine()">
                            <x-icon name="plus" class="icon-sm" /> Add line
                        </button>
                    </div>
                </div>
                <div class="po-form-foot">
                    <button type="button" class="btn btn-secondary" :disabled="edit.busy" @click="cancelEdit()">Cancel</button>
                    <button type="submit" class="btn btn-primary" :disabled="edit.busy" :aria-busy="edit.busy">
                        <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                        Save lines
                    </button>
                </div>
            </form>

            {{-- Activity (E29), filtered to this order --}}
            <section class="card">
                <div class="card-header"><h2>Activity</h2></div>
                <div class="card-body">
                    <div class="stack stack-sm" x-show="activity.loading" aria-busy="true">
                        <div class="skeleton skeleton-line w-75"></div>
                        <div class="skeleton skeleton-line w-50"></div>
                    </div>
                    <div class="row" x-show="! activity.loading && activity.error" x-cloak>
                        <p class="muted grow" x-text="activity.error"></p>
                        <button type="button" class="btn btn-secondary" @click="loadActivity()"><x-icon name="refresh" class="icon-sm" /> Retry</button>
                    </div>
                    <p class="muted" x-show="! activity.loading && ! activity.error && activity.items.length === 0" x-cloak>
                        Nothing has happened to this order yet.
                    </p>
                    <ul class="po-activity" x-show="! activity.loading && ! activity.error && activity.items.length > 0" x-cloak>
                        <template x-for="(entry, index) in activity.items" :key="index + ':' + entry.created_at + ':' + entry.event">
                            <li>
                                <span class="grow" x-text="entry.description"></span>
                                <span class="pill" data-tone="neutral" x-text="entry.channel"></span>
                                <span class="text-sm muted" :title="when(entry.created_at)" x-text="ago(entry.created_at)"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </section>
        </div>
    </template>

    {{-- Receive delivery. A native dialog, like the shared confirm one: Escape closes it, focus stays inside. --}}
    <dialog class="dialog dialog-wide" x-ref="receiveDialog" aria-labelledby="receive-title">
        <form novalidate @submit.prevent="reviewReceive()">
            <div class="dialog-head">
                <h2 id="receive-title">Receive delivery</h2>
                <p class="hint">Filled with what is still outstanding. Change a quantity to what actually arrived, or set 0 for a line that did not.</p>
            </div>
            <div class="dialog-body stack">
                <template x-for="line in recv.lines" :key="line.key">
                    <div class="rcv-line">
                        <div class="rcv-name">
                            <strong class="text" x-text="line.name"></strong>
                            <span class="text-sm muted" x-text="limitText(line)"></span>
                        </div>
                        <div class="rcv-qty">
                            @include('pages.purchasing.quantity-by-unit', [
                                'model' => 'line.quantity',
                                'unit' => 'line.unit',
                                'change' => 'line.mode = $event.detail.mode; line.error = $event.detail.error',
                                'label' => 'Quantity received',
                                'allowZero' => true,
                                'class' => 'rcv-field',
                            ])
                        </div>
                        <div class="rcv-errors">
                            <template x-for="message in rowMessages(line)" :key="message">
                                <p class="inline-error" role="alert"><x-icon name="alert" /> <span x-text="message"></span></p>
                            </template>
                        </div>
                    </div>
                </template>

                <div class="stack stack-sm">
                    <label class="rcv-check">
                        <input type="checkbox" x-model="recv.customTime">
                        <span>Set a different time (default: received now)</span>
                    </label>
                    <div class="field" x-show="recv.customTime" x-cloak x-id="['received']">
                        <label :for="$id('received')">Received at</label>
                        <input class="input" type="datetime-local" :id="$id('received')" x-model="recv.receivedAt" :max="maxTime()">
                    </div>
                    <p class="inline-error" role="alert" x-show="recv.timeError || (recv.errors.received_at && recv.errors.received_at[0])">
                        <x-icon name="alert" /> <span x-text="recv.timeError || (recv.errors.received_at && recv.errors.received_at[0])"></span>
                    </p>
                </div>

                <div class="field" x-id="['note']">
                    <label :for="$id('note')">Note (optional)</label>
                    <input class="input" type="text" maxlength="255" :id="$id('note')" x-model="recv.note" placeholder="Driver, invoice number, damage">
                    <p class="inline-error" role="alert" x-show="recv.errors.note && recv.errors.note[0]">
                        <x-icon name="alert" /> <span x-text="recv.errors.note && recv.errors.note[0]"></span>
                    </p>
                </div>

                <p class="inline-error" role="alert" x-show="recv.formError">
                    <x-icon name="alert" /> <span x-text="recv.formError"></span>
                </p>
            </div>
            <div class="dialog-foot">
                <button type="button" class="btn btn-secondary" @click="closeReceive()">Cancel</button>
                <button type="submit" class="btn btn-primary">Review delivery</button>
            </div>
        </form>
    </dialog>
</div>
@endsection
