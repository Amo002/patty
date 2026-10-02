@extends('layouts.app')

@section('title', 'Ingredients')
@section('live', '1')

@section('actions')
    {{-- The header sits outside the page component, so the button talks to it with a window event. --}}
    <button type="button" class="btn btn-primary" @click="$dispatch('ingredient-create')">
        <x-icon name="plus" class="icon-sm" /> Add ingredient
    </button>
@endsection

@section('content')
    @include('pages.catalogue.assets', ['script' => 'ingredients'])

    <div x-data="ingredientsPage" @ingredient-create.window="openCreate()" class="stack">

        {{-- Error: says what failed, offers a retry, shows the request id (U4). --}}
        <div class="error-banner" x-show="status === 'error'" x-cloak role="alert">
            <x-icon name="alert" />
            <span class="grow">
                <span x-text="error && error.message"></span>
                <span class="muted text-sm" x-show="error && error.requestId" x-text="'Request id: ' + (error && error.requestId)"></span>
            </span>
            <button type="button" class="btn btn-secondary" @click="load()"><x-icon name="refresh" class="icon-sm" /> Try again</button>
        </div>

        {{-- Empty: guides to the next action (design.md rule 5). --}}
        <div x-show="status === 'empty'" x-cloak>
            <x-empty-state title="No ingredients yet" text="Add what you cook with, like beef, buns or cheese. Recipes and purchase orders are built from them." icon="ingredients">
                <button type="button" class="btn btn-primary" @click="openCreate()"><x-icon name="plus" class="icon-sm" /> Add ingredient</button>
            </x-empty-state>
        </div>

        <section class="card" x-show="status === 'loading' || status === 'loaded'" x-cloak>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><span class="visually-hidden">Photo</span></th>
                            <th>Ingredient</th>
                            <th class="num">On hand</th>
                            <th>Unit</th>
                            <th>Delivery tolerance</th>
                            <th class="actions"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Skeleton rows are the same height as real ones, so nothing shifts when data lands (U1). --}}
                        <template x-if="status === 'loading'">
                            <template x-for="n in 6" :key="'sk' + n">
                                <tr aria-hidden="true">
                                    <td><div class="skeleton skeleton-block thumb"></div></td>
                                    <td><div class="skeleton skeleton-line w-50"></div></td>
                                    <td><div class="skeleton skeleton-line w-25 skeleton-end"></div></td>
                                    <td><div class="skeleton skeleton-line w-25"></div></td>
                                    <td><div class="skeleton skeleton-line w-75"></div></td>
                                    <td></td>
                                </tr>
                            </template>
                        </template>
                        <template x-for="item in items" :key="item.id">
                            <tr :data-negative="item.is_negative" :class="{ 'row-changed': changedId === item.id }">
                                <td class="thumb-cell"><x-photo class="thumb" x-bind:src="item.image_url" ratio="1 / 1" /></td>
                                <td><strong x-text="item.name"></strong></td>
                                <td class="num">
                                    <span class="stock-cell">
                                        <span class="pill" :data-tone="meta('negative').tone" x-show="item.is_negative" x-text="meta('negative').label"></span>
                                        <span x-flash="item.on_hand" :class="item.is_negative ? 'text-danger' : ''" x-text="show(item).text" :title="show(item).exact"></span>
                                    </span>
                                </td>
                                <td x-text="item.unit_label || item.unit"></td>
                                <td class="tabular">
                                    <span x-text="toleranceText(item)"></span>
                                    <span class="muted text-xs" x-show="item.tolerance && item.tolerance.source === 'default'">default</span>
                                </td>
                                <td class="actions">
                                    <button type="button" class="btn btn-ghost" @click="openHistory(item)" :aria-label="'Stock history for ' + item.name">History</button>
                                    <button type="button" class="btn btn-ghost btn-icon" :aria-label="'Edit ' + item.name" @click="openEdit(item)"><x-icon name="edit" /></button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Lazy loading: the sentinel loads the next page near the viewport; the button is the keyboard fallback (U2). --}}
        <div class="row load-more" x-show="status === 'loaded' && hasMore" x-cloak>
            <button type="button" class="btn btn-secondary" :disabled="loadingMore" :aria-busy="loadingMore" @click="loadMore()">
                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                <span x-text="moreError ? 'Could not load more. Try again' : 'Load more'"></span>
            </button>
        </div>
        <div x-init="watchEnd($el)" aria-hidden="true"></div>

        {{-- Create and edit --}}
        <dialog class="dialog dialog-wide" x-ref="dialog" aria-labelledby="ingredient-form-title" @cancel="busy && $event.preventDefault()">
            <form @submit.prevent="save()" novalidate>
                <div class="dialog-head"><h2 id="ingredient-form-title" x-text="form.id ? 'Edit ingredient' : 'Add ingredient'"></h2></div>
                <div class="dialog-body stack">
                    <p class="inline-error" role="alert" x-show="form.notice"><x-icon name="alert" /> <span x-text="form.notice"></span></p>

                    <div class="grid-2">
                        <div class="field">
                            <label for="ingredient-name">Name</label>
                            <input id="ingredient-name" class="input" x-model="form.name" maxlength="100" autocomplete="off" required
                                   placeholder="Beef" :aria-invalid="err('name') !== ''" aria-describedby="ingredient-name-msg">
                            <p id="ingredient-name-msg" class="inline-error" role="alert" x-show="err('name')"><x-icon name="alert" /> <span x-text="err('name')"></span></p>
                        </div>
                        <div class="field">
                            <label for="ingredient-unit">Unit</label>
                            <select id="ingredient-unit" class="select" x-model="form.unit" @change="unitChanged()" :disabled="form.unitLocked"
                                    :aria-invalid="err('unit') !== ''" aria-describedby="ingredient-unit-msg">
                                <option value="g">Grams (g)</option>
                                <option value="ml">Millilitres (ml)</option>
                                <option value="piece">Pieces</option>
                            </select>
                            <p id="ingredient-unit-msg" class="hint" x-show="form.unitLocked">Locked: this ingredient is already used in stock history, recipes or purchase orders, so its unit (<span x-text="form.originalUnit"></span>) cannot change.</p>
                            <p class="inline-error" role="alert" x-show="err('unit')"><x-icon name="alert" /> <span x-text="err('unit')"></span></p>
                        </div>
                    </div>

                    <fieldset class="stack stack-sm tolerance">
                        <legend class="label">Delivery tolerance <span class="muted">(optional)</span></legend>
                        <p class="hint">How far a delivery may differ from what was ordered. Leave a field empty to use the standard tolerance.</p>
                        <div class="grid-2">
                            <div class="field">
                                <label for="ingredient-over">Over-delivery allowed (%)</label>
                                <input id="ingredient-over" class="input tabular" inputmode="decimal" x-model="form.overPct" autocomplete="off" placeholder="default"
                                       :aria-invalid="err('over_tolerance_bps') !== ''" aria-describedby="ingredient-over-msg">
                                <p id="ingredient-over-msg" class="inline-error" role="alert" x-show="err('over_tolerance_bps')"><x-icon name="alert" /> <span x-text="err('over_tolerance_bps')"></span></p>
                            </div>
                            <div class="field">
                                <label for="ingredient-under">Under-delivery accepted (%)</label>
                                <input id="ingredient-under" class="input tabular" inputmode="decimal" x-model="form.underPct" autocomplete="off" placeholder="default"
                                       :aria-invalid="err('under_tolerance_bps') !== ''" aria-describedby="ingredient-under-msg">
                                <p id="ingredient-under-msg" class="inline-error" role="alert" x-show="err('under_tolerance_bps')"><x-icon name="alert" /> <span x-text="err('under_tolerance_bps')"></span></p>
                            </div>
                        </div>

                        {{-- The quantity input fixes its unit when it renders, so one copy exists per unit and x-if picks the one that matches. --}}
                        @foreach (['g', 'ml', 'piece'] as $unit)
                            <template x-if="form.capShow && form.unit === '{{ $unit }}'">
                                <x-quantity-input unit="{{ $unit }}" context="purchase" label="Largest over-delivery accepted (optional)" :allow-zero="true"
                                                  x-model="form.cap" @quantity-change="form.capError = $event.detail.error" />
                            </template>
                        @endforeach
                        <p class="inline-error" role="alert" x-show="err('over_tolerance_cap') && !form.capError"><x-icon name="alert" /> <span x-text="err('over_tolerance_cap')"></span></p>

                        <div>
                            <button type="button" class="btn btn-ghost" x-show="hasToleranceInput()" @click="useDefaultTolerance()">Use default</button>
                        </div>
                    </fieldset>
                </div>
                <div class="dialog-foot">
                    <button type="button" class="btn btn-secondary" :disabled="busy" @click="closeForm()">Cancel</button>
                    <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">
                        <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span x-text="form.id ? 'Save changes' : 'Add ingredient'"></span>
                    </button>
                </div>
            </form>
        </dialog>

        {{-- History drawer (E6): every movement of one ingredient, newest first, with the balance after each. --}}
        <dialog class="dialog drawer" x-ref="drawer" aria-labelledby="history-title" @close="resetHistory()" @click="if ($event.target === $el) closeHistory()">
            <div class="dialog-head row row-between">
                <div>
                    <h2 id="history-title" x-text="historyItem ? 'History: ' + historyItem.name : 'History'"></h2>
                    <p class="muted text-sm" x-show="historyItem">Every change to the stock count, newest first.</p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" aria-label="Close history" @click="closeHistory()"><x-icon name="close" /></button>
            </div>
            <div class="drawer-body">
                <template x-if="history">
                    <div class="stack">
                        <div class="error-banner" x-show="history.status === 'error'" role="alert">
                            <x-icon name="alert" />
                            <span class="grow">
                                {{-- The movements endpoint arrives with PTY-10, so a 404 is "not built yet", not a failure. --}}
                                <template x-if="history.error && history.error.status === 404">
                                    <span>History will appear here once stock movements are available.</span>
                                </template>
                                <template x-if="history.error && history.error.status !== 404">
                                    <span x-text="history.error.message"></span>
                                </template>
                                <span class="muted text-sm" x-show="history.error && history.error.requestId" x-text="'Request id: ' + (history.error && history.error.requestId)"></span>
                            </span>
                            <button type="button" class="btn btn-secondary" @click="history.load()"><x-icon name="refresh" class="icon-sm" /> Try again</button>
                        </div>

                        <div x-show="history.status === 'empty'">
                            <x-empty-state title="No stock movements yet" text="Deliveries, sales and corrections will show up here as they happen." icon="ingredients" />
                        </div>

                        <div class="stack stack-sm" x-show="history.status === 'loading'" aria-hidden="true">
                            <div class="skeleton skeleton-block"></div>
                            <div class="skeleton skeleton-block"></div>
                            <div class="skeleton skeleton-block"></div>
                        </div>

                        <ol class="history" x-show="history.status === 'loaded'">
                            <template x-for="(movement, index) in history.items" :key="index">
                                <li class="history-row">
                                    <div class="grow">
                                        <strong x-text="movement.reason_label"></strong>
                                        <div class="muted text-sm" x-show="movement.reference && movement.reference.label" x-text="movement.reference && movement.reference.label"></div>
                                        <div class="muted text-xs" :title="localTime(movement.occurred_at)" x-text="timeAgo(movement.occurred_at) + ' (' + localTime(movement.occurred_at) + ')'"></div>
                                    </div>
                                    <div class="history-numbers num">
                                        <span :class="movement.quantity_delta < 0 ? 'text-danger' : 'text-ok'" x-text="delta(movement)"></span>
                                        <span class="muted text-sm" :title="balance(movement).exact" x-text="'Balance ' + balance(movement).text"></span>
                                    </div>
                                </li>
                            </template>
                        </ol>

                        <div class="row load-more" x-show="history.status === 'loaded' && history.hasMore">
                            <button type="button" class="btn btn-secondary" :disabled="history.loadingMore" :aria-busy="history.loadingMore" @click="history.loadMore()">
                                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                                <span x-text="history.moreError ? 'Could not load more. Try again' : 'Load more'"></span>
                            </button>
                        </div>
                        <div x-init="history.watchEnd($el)" aria-hidden="true"></div>
                    </div>
                </template>
            </div>
        </dialog>
    </div>
@endsection
