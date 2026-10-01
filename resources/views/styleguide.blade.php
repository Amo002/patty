{{--
    Every component in every state, for design review (local only, see routes/web.php).
    Switch light, dark and system from the identity chip at the top right: the whole page follows.
--}}
@extends('layouts.app')

@section('title', 'Styleguide')
@section('live', '1')

@push('scripts')
    <script>
        // Demo state only. Real pages follow the same shape: load with Patty.api, render with x-text, poll with Patty.api.poll.
        document.addEventListener('alpine:init', function () {
            Alpine.data('styleguide', function () {
                return {
                    beef: 2400,
                    milk: null,
                    buns: 12,
                    busy: false,
                    rows: [
                        { id: 1, name: 'Beef', unit: 'g', on_hand: 52000 },
                        { id: 2, name: 'Cheese', unit: 'g', on_hand: 750 },
                        { id: 3, name: 'Burger bun', unit: 'piece', on_hand: 2400 },
                        { id: 4, name: 'Milk', unit: 'ml', on_hand: -1500 },
                    ],
                    nextId: 5,
                    units: Patty.units,
                    meta: function (status) { return Patty.statusMeta(status); },
                    show: function (row) { return Patty.units.display(row.on_hand, row.unit); },
                    fakeBusy: function () {
                        var self = this;
                        this.busy = true;
                        setTimeout(function () { self.busy = false; }, 2000);
                    },
                    refetch: function () {
                        // A real refetch replaces the numbers. x-flash then highlights those that moved.
                        this.rows.forEach(function (row) { row.on_hand += Math.round((Math.random() - 0.4) * 600); });
                        Patty.markFresh();
                    },
                    addRow: function () {
                        this.rows.push({ id: this.nextId++, name: 'New ingredient ' + (this.nextId - 1), unit: 'g', on_hand: 1200 });
                    },
                    confirmSimple: function () {
                        Patty.confirm({
                            title: 'Send this purchase order?',
                            message: 'It can no longer be edited after sending.',
                            confirmLabel: 'Send order',
                        }).then(function (yes) { Patty.notify({ tone: 'info', message: yes ? 'Confirmed.' : 'Cancelled.' }); });
                    },
                    confirmBusy: function () {
                        Patty.confirm({
                            title: 'Record this delivery?',
                            message: 'This adds to stock and cannot be edited afterwards.',
                            lines: ['Add 600 g Beef to stock', 'Add 10 pcs Burger bun to stock'],
                            confirmLabel: 'Record delivery',
                            run: function () { return new Promise(function (resolve) { setTimeout(resolve, 1200); }); },
                        }).then(function (yes) { if (yes) Patty.notify({ tone: 'success', message: 'Delivery recorded.' }); });
                    },
                    confirmFails: function () {
                        Patty.confirm({
                            title: 'Delete this draft?',
                            message: 'The draft and its lines are removed.',
                            confirmLabel: 'Delete draft',
                            tone: 'danger',
                            run: function () { return new Promise(function (resolve, reject) { setTimeout(function () { reject(new Error('Beef: 1.1 kg is above the 1.05 kg limit')); }, 600); }); },
                        });
                    },
                    replayLoader: function () {
                        try { sessionStorage.removeItem('patty-loader'); } catch (e) {}
                        location.reload();
                    },
                };
            });
        });
    </script>
@endpush

@section('actions')
    <button type="button" class="btn btn-secondary" onclick="Patty.markFresh()">Mark fresh</button>
    <button type="button" class="btn btn-secondary" x-data="styleguide" @click="replayLoader()">Replay loader</button>
@endsection

@section('content')
<div class="stack" x-data="styleguide" style="gap: var(--sp-6)">

    <section class="card">
        <div class="card-header"><h2>Colour tokens</h2><span class="muted text-sm">Follows the theme toggle</span></div>
        <div class="card-body">
            <div class="grid-kpi">
                @foreach (['bg', 'surface', 'surface-2', 'border', 'border-strong', 'text', 'muted', 'accent', 'accent-text', 'on-accent', 'accent-subtle', 'ok', 'warn-text', 'danger', 'danger-text', 'danger-subtle', 'info'] as $token)
                    <div class="row" style="flex-wrap: nowrap">
                        <span style="width: 40px; height: 40px; border-radius: var(--r-sm); border: 1px solid var(--border-strong); background: var(--{{ $token }}); flex: none"></span>
                        <span class="mono">--{{ $token }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Type</h2><span class="muted text-sm">Inter, variable, self-hosted</span></div>
        <div class="card-body stack stack-sm">
            <div style="font-size: var(--fs-36); font-weight: 700; letter-spacing: -0.02em">36 Heading</div>
            <div style="font-size: var(--fs-28); font-weight: 650; letter-spacing: -0.02em">28 Page title</div>
            <div style="font-size: var(--fs-20); font-weight: 650; letter-spacing: -0.02em">20 Card title</div>
            <div style="font-size: var(--fs-16)">16 Body text, the quick brown fox jumps over the lazy dog</div>
            <div style="font-size: var(--fs-14)">14 UI text 0123456789 (tabular numbers: 1,111 and 8,888 align)</div>
            <div class="muted" style="font-size: var(--fs-12)">12 Caption and muted label</div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Buttons</h2></div>
        <div class="card-body stack">
            <div class="row">
                <button type="button" class="btn btn-primary">Primary</button>
                <button type="button" class="btn btn-secondary">Secondary</button>
                <button type="button" class="btn btn-ghost">Ghost</button>
                <button type="button" class="btn btn-danger">Danger</button>
                <button type="button" class="btn btn-secondary btn-icon" aria-label="Edit"><x-icon name="edit" /></button>
                <button type="button" class="btn btn-primary"><x-icon name="plus" class="icon-sm" /> With icon</button>
            </div>
            <div class="row">
                <button type="button" class="btn btn-primary" disabled>Disabled</button>
                <button type="button" class="btn btn-secondary" disabled>Disabled</button>
                <button type="button" class="btn btn-primary" aria-busy="true">
                    <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span> Busy (static)
                </button>
                <button type="button" class="btn btn-danger" aria-busy="true">
                    <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span> Busy
                </button>
                <button type="button" class="btn btn-primary" :aria-busy="busy" @click="fakeBusy()">
                    <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span> Click for 2 s busy
                </button>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Fields</h2></div>
        <div class="card-body stack">
            <div class="grid-2">
                <div class="field">
                    <label for="sg-name">Name</label>
                    <input id="sg-name" class="input" placeholder="Beef">
                    <span class="hint">Shown on purchase orders.</span>
                </div>
                <div class="field">
                    <label for="sg-name-err">Name with an error</label>
                    <input id="sg-name-err" class="input" value="B" aria-invalid="true">
                    <p class="inline-error"><x-icon name="alert" /> Name must be at least 2 characters.</p>
                </div>
                <div class="field">
                    <label for="sg-unit">Unit</label>
                    <select id="sg-unit" class="select"><option>g</option><option>ml</option><option>piece</option></select>
                </div>
                <div class="field">
                    <label for="sg-dis">Disabled</label>
                    <input id="sg-dis" class="input" value="Locked once stock has moved" disabled>
                </div>
            </div>

            <h3>Quantity inputs</h3>
            <div class="grid-2">
                <div class="stack stack-sm">
                    <x-quantity-input unit="g" context="purchase" label="Beef, PO line (starts in kg)" x-model="beef" hint="Up to 1.05 kg" />
                    <p class="text-sm muted">Sent as <strong class="text" x-text="beef === null ? 'nothing yet' : beef + ' g'"></strong></p>
                </div>
                <div class="stack stack-sm">
                    <x-quantity-input unit="ml" context="recipe" label="Milk, recipe line (starts in ml)" x-model="milk" />
                    <p class="text-sm muted">Sent as <strong class="text" x-text="milk === null ? 'nothing yet' : milk + ' ml'"></strong></p>
                </div>
                <div class="stack stack-sm">
                    <x-quantity-input unit="piece" label="Burger bun (pieces, no switch)" x-model="buns" />
                    <p class="text-sm muted">Sent as <strong class="text" x-text="buns === null ? 'nothing yet' : buns + ' pcs'"></strong></p>
                </div>
            </div>
            <p class="hint">Try 1.0005 in kg (more than 3 decimals), or 2.5 in g (not whole). The error appears before anything is sent.</p>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Status pills</h2></div>
        <div class="card-body row">
            <x-status-pill status="draft" />
            <x-status-pill status="sent" />
            <x-status-pill status="received" />
            <x-status-pill status="closed" />
            <x-status-pill status="closed" :short-closed="true" />
            <x-status-pill status="negative" />
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Progress</h2></div>
        <div class="card-body stack">
            @foreach ([0, 40, 85, 100] as $value)
                <div class="row" style="flex-wrap: nowrap">
                    <div class="progress grow" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $value }}" aria-label="Received" @if ($value === 100) data-complete="true" @endif style="--value: {{ $value }}%"><span></span></div>
                    <span class="num text-sm" style="width: 4ch">{{ $value }}%</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="stack">
        <div class="grid-kpi">
            <div class="card kpi"><span class="kpi-label">Ingredients</span><span class="kpi-value">24</span><span class="kpi-note">in the catalogue</span></div>
            <div class="card kpi" data-tone="danger"><span class="kpi-label">Negative stock</span><span class="kpi-value">2</span><span class="kpi-note">Check counts and deliveries</span></div>
            <div class="card kpi"><span class="kpi-label">Open orders</span><span class="kpi-value">5</span><span class="kpi-note">sent or part received</span></div>
            <div class="card kpi"><div class="skeleton skeleton-line w-50"></div><div class="skeleton skeleton-kpi" style="margin-top: 8px"></div><div class="skeleton skeleton-line w-75" style="margin-top: 8px"></div></div>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <h2>Table, rows entering, numbers changing</h2>
            <div class="row">
                <button type="button" class="btn btn-secondary" @click="refetch()"><x-icon name="refresh" class="icon-sm" /> Simulate refetch</button>
                <button type="button" class="btn btn-primary" @click="addRow()"><x-icon name="plus" class="icon-sm" /> Add row</button>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Ingredient</th><th class="num">On hand</th><th>Status</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <template x-for="row in rows" :key="row.id">
                        <tr :data-negative="row.on_hand < 0">
                            <td x-text="row.name"></td>
                            <td class="num">
                                <span x-flash="row.on_hand" :class="row.on_hand < 0 ? 'text-danger' : ''" x-text="show(row).text" :title="show(row).exact"></span>
                            </td>
                            <td>
                                <span class="pill" :data-tone="row.on_hand < 0 ? meta('negative').tone : 'ok'" x-text="row.on_hand < 0 ? meta('negative').label : 'In stock'"></span>
                            </td>
                            <td class="actions"><button type="button" class="btn btn-ghost btn-icon" aria-label="Edit"><x-icon name="edit" /></button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Dialogs and toasts</h2></div>
        <div class="card-body stack">
            <div class="row">
                <button type="button" class="btn btn-secondary" @click="confirmSimple()">Confirm dialog</button>
                <button type="button" class="btn btn-primary" @click="confirmBusy()">Confirm with a busy request</button>
                <button type="button" class="btn btn-danger" @click="confirmFails()">Confirm that fails</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('sg-dialog').showModal()">Plain dialog</button>
            </div>
            <div class="row">
                <button type="button" class="btn btn-secondary" onclick="Patty.notify({ tone: 'success', message: 'Supplier saved.' })">Success toast</button>
                <button type="button" class="btn btn-secondary" onclick="Patty.notify({ tone: 'info', message: 'Menu item updated.' })">Info toast</button>
                <button type="button" class="btn btn-secondary" onclick="Patty.notify({ tone: 'warn', message: 'This order was already sent. The view has been refreshed.', requestId: '01J9ZK3W5Y7Q' })">Notice (409)</button>
                <button type="button" class="btn btn-secondary" onclick="Patty.notify({ tone: 'error', message: 'Something went wrong on our side.', requestId: '01J9ZK3W5Y7Q' })">Error (500)</button>
            </div>
        </div>
    </section>

    <dialog id="sg-dialog" class="dialog" aria-labelledby="sg-dialog-title">
        <div class="dialog-head"><h2 id="sg-dialog-title">A plain dialog</h2></div>
        <div class="dialog-body">Native dialog element: Escape closes it, focus is trapped, the backdrop fades.</div>
        <div class="dialog-foot"><button type="button" class="btn btn-primary" onclick="this.closest('dialog').close()">Close</button></div>
    </dialog>

    <section class="card">
        <div class="card-header"><h2>Errors and notices</h2></div>
        <div class="card-body stack">
            <p class="inline-error"><x-icon name="alert" /> Beef: 1.1 kg is above the 1.05 kg limit</p>
            <div class="error-banner">
                <x-icon name="alert" />
                <span class="grow">Could not load stock. Request id 01J9ZK3W5Y7Q.</span>
                <button type="button" class="btn btn-secondary"><x-icon name="refresh" class="icon-sm" /> Retry</button>
            </div>
        </div>
    </section>

    <section class="stack">
        <x-empty-state title="No suppliers yet" text="Add one to start ordering." icon="supplier">
            <button type="button" class="btn btn-primary"><x-icon name="plus" class="icon-sm" /> Add supplier</button>
        </x-empty-state>
    </section>

    <section class="card">
        <div class="card-header"><h2>Skeletons</h2></div>
        <div class="card-body stack stack-sm">
            <div class="skeleton skeleton-line w-25"></div>
            <div class="skeleton skeleton-line"></div>
            <div class="skeleton skeleton-line w-75"></div>
            <div class="skeleton skeleton-block"></div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Photo</h2></div>
        <div class="card-body row" style="align-items: flex-start">
            <div style="width: 160px"><x-photo src="/missing-image.jpg" alt="Image that fails to load, icon shows" /><p class="hint">Broken URL</p></div>
            <div style="width: 160px"><x-photo alt="No image set" ratio="1 / 1" /><p class="hint">No image</p></div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Unit display</h2><span class="muted text-sm">Hover for the exact value</span></div>
        <div class="card-body row">
            @foreach ([[750, 'g'], [2400, 'g'], [52000, 'g'], [1005, 'g'], [-20, 'g'], [-1500, 'ml'], [2400, 'piece']] as [$q, $u])
                <span class="pill" data-tone="neutral" x-text="units.display({{ $q }}, '{{ $u }}').text" :title="units.display({{ $q }}, '{{ $u }}').exact"></span>
            @endforeach
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Icons</h2></div>
        <div class="card-body grid-kpi">
            @foreach (collect(glob(resource_path('icons/*.svg')))->map(fn ($f) => basename($f, '.svg')) as $icon)
                <div class="row" style="flex-wrap: nowrap"><x-icon :name="$icon" /> <span class="mono">{{ $icon }}</span></div>
            @endforeach
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Brand</h2></div>
        <div class="card-body row" style="gap: var(--sp-6)">
            <img src="/brand/logo.svg" alt="Patty logo" width="148" height="40">
            <img src="/brand/favicon.svg" alt="Favicon" width="32" height="32">
            <img src="/brand/favicon-32.png" alt="Favicon PNG 32" width="32" height="32">
            <img src="/brand/apple-touch-icon.png" alt="Apple touch icon" width="60" height="60">
        </div>
    </section>
</div>
@endsection
