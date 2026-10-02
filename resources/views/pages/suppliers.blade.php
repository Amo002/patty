@extends('layouts.app')

@section('title', 'Suppliers')

@section('actions')
    {{-- The header sits outside the page component, so the button talks to it with a window event. --}}
    <button type="button" class="btn btn-primary" @click="$dispatch('supplier-create')">
        <x-icon name="plus" class="icon-sm" /> Add supplier
    </button>
@endsection

@section('content')
    @include('pages.catalogue.assets', ['script' => 'suppliers'])

    <div x-data="suppliersPage" @supplier-create.window="openCreate()" class="stack">

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
            <x-empty-state title="No suppliers yet" text="Add the people you buy from. You pick one whenever you draft a purchase order." icon="supplier">
                <button type="button" class="btn btn-primary" @click="openCreate()"><x-icon name="plus" class="icon-sm" /> Add supplier</button>
            </x-empty-state>
        </div>

        <section class="card" x-show="status === 'loading' || status === 'loaded'" x-cloak>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th class="actions"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Skeleton rows are the same height as real ones, so nothing shifts when data lands (U1). --}}
                        <template x-if="status === 'loading'">
                            <template x-for="n in 5" :key="'sk' + n">
                                <tr aria-hidden="true">
                                    <td><div class="skeleton skeleton-line w-50"></div></td>
                                    <td><div class="skeleton skeleton-line w-75"></div></td>
                                    <td><div class="skeleton skeleton-line w-50"></div></td>
                                    <td></td>
                                </tr>
                            </template>
                        </template>
                        <template x-for="supplier in items" :key="supplier.id">
                            <tr :class="{ 'row-changed': changedId === supplier.id }">
                                <td><strong x-text="supplier.name"></strong></td>
                                <td x-text="supplier.email || '-'" :class="supplier.email ? '' : 'muted'"></td>
                                <td x-text="supplier.phone || '-'" :class="supplier.phone ? '' : 'muted'" class="tabular"></td>
                                <td class="actions">
                                    <button type="button" class="btn btn-ghost btn-icon" :aria-label="'Edit ' + supplier.name" @click="openEdit(supplier)">
                                        <x-icon name="edit" />
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Lazy loading: the sentinel loads the next page near the viewport; the button is the keyboard fallback (U2). --}}
        <div class="row" style="justify-content: center" x-show="status === 'loaded' && hasMore" x-cloak>
            <button type="button" class="btn btn-secondary" :disabled="loadingMore" :aria-busy="loadingMore" @click="loadMore()">
                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                <span x-text="moreError ? 'Could not load more. Try again' : 'Load more'"></span>
            </button>
        </div>
        <div x-init="watchEnd($el)" aria-hidden="true"></div>

        <dialog class="dialog" x-ref="dialog" aria-labelledby="supplier-form-title" @cancel="busy && $event.preventDefault()">
            <form @submit.prevent="save()" novalidate>
                <div class="dialog-head"><h2 id="supplier-form-title" x-text="form.id ? 'Edit supplier' : 'Add supplier'"></h2></div>
                <div class="dialog-body stack">
                    <div class="field">
                        <label for="supplier-name">Name</label>
                        <input id="supplier-name" class="input" x-model="form.name" maxlength="120" autocomplete="off" required
                               placeholder="Al-Mashreq Meats" :aria-invalid="err('name') !== ''" aria-describedby="supplier-name-msg">
                        <p id="supplier-name-msg" class="inline-error" role="alert" x-show="err('name')"><x-icon name="alert" /> <span x-text="err('name')"></span></p>
                    </div>
                    <div class="field">
                        <label for="supplier-email">Email <span class="muted">(optional)</span></label>
                        <input id="supplier-email" class="input" type="email" x-model="form.email" maxlength="255" autocomplete="off"
                               placeholder="orders@example.com" :aria-invalid="err('email') !== ''" aria-describedby="supplier-email-msg">
                        <p id="supplier-email-msg" class="inline-error" role="alert" x-show="err('email')"><x-icon name="alert" /> <span x-text="err('email')"></span></p>
                    </div>
                    <div class="field">
                        <label for="supplier-phone">Phone <span class="muted">(optional)</span></label>
                        <input id="supplier-phone" class="input" type="tel" x-model="form.phone" maxlength="30" autocomplete="off"
                               placeholder="+962 6 555 0101" :aria-invalid="err('phone') !== ''" aria-describedby="supplier-phone-msg">
                        <p id="supplier-phone-msg" class="inline-error" role="alert" x-show="err('phone')"><x-icon name="alert" /> <span x-text="err('phone')"></span></p>
                    </div>
                </div>
                <div class="dialog-foot">
                    <button type="button" class="btn btn-secondary" :disabled="busy" @click="closeForm()">Cancel</button>
                    <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">
                        <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span x-text="form.id ? 'Save changes' : 'Add supplier'"></span>
                    </button>
                </div>
            </form>
        </dialog>
    </div>
@endsection
