{{--
    Activity (PTY-12): every recorded event, newest first, with the channel it came from (D-021).
    Reached from a purchase order as ?subject_type=purchase_order&subject_id=<ulid>.
    All data is rendered with x-text and attribute bindings, never as HTML (U12).
--}}
@extends('layouts.app')

@section('title', 'Activity')
@section('live', '1')

@push('scripts')
    @php
        $v = fn (string $path) => '/'.$path.'?v='.(is_file(public_path($path)) ? filemtime(public_path($path)) : '0');
    @endphp
    <link rel="stylesheet" href="{{ $v('css/pages/dashboard.css') }}">
    <script defer src="{{ $v('js/pages/live-list.js') }}"></script>
    <script defer src="{{ $v('js/pages/activity.js') }}"></script>
@endpush

@section('content')
<div class="stack" x-data="activity">

    <section class="card" aria-labelledby="activity-title">
        <div class="card-header">
            <h2 id="activity-title">What happened</h2>
            <span class="muted text-sm">Newest first. Times are in your timezone; hover for the exact time.</span>
        </div>

        <div class="filter-bar">
            <div class="field">
                <label for="activity-type">Show</label>
                <select id="activity-type" class="select" x-model="typeFilter" @change="pickType()">
                    <option value="">Everything</option>
                    {{-- Static, so the saved choice is selected on first paint. Same list as ListActivityRequest::SUBJECT_TYPES. --}}
                    <option value="purchase_order">Purchase orders</option>
                    <option value="ingredient">Ingredients</option>
                    <option value="supplier">Suppliers</option>
                    <option value="menu_item">Menu items</option>
                    <option value="sale">Sales</option>
                </select>
            </div>
            <div class="row" x-show="subject" x-cloak>
                <span class="pill" data-tone="info" x-text="'Only ' + subjectLabel()"></span>
                <button type="button" class="btn btn-ghost" @click="showAll()">Show all activity</button>
            </div>
        </div>

        <div class="error-banner panel-error" role="alert" x-show="list.error" x-cloak>
            <x-icon name="alert" />
            <span class="grow">
                <span x-text="list.error && list.error.message"></span>
                <span class="text-xs" x-show="list.error && list.error.requestId" x-text="'Request id: ' + (list.error && list.error.requestId)"></span>
            </span>
            <button type="button" class="btn btn-secondary" @click="list.rows.length ? list.refresh().catch(() => {}) : list.load().catch(() => {})">
                <x-icon name="refresh" class="icon-sm" /> Retry
            </button>
        </div>

        {{-- Loading: rows shaped like the timeline entries (U1). --}}
        <div class="card-body stack" x-show="list.loading && list.rows.length === 0" aria-busy="true">
            <template x-for="n in 5" :key="n">
                <div class="stack stack-sm">
                    <div class="skeleton skeleton-line w-75"></div>
                    <div class="skeleton skeleton-line w-25"></div>
                </div>
            </template>
        </div>

        {{-- Empty: say what is missing and where to make something happen (U4). --}}
        <div class="card-body" x-show="! list.loading && ! list.error && ! list.hasMore && visible().length === 0" x-cloak>
            <div x-show="subject || typeFilter">
                <x-empty-state title="Nothing recorded here yet" text="Nothing has happened for this selection." icon="activity">
                    <button type="button" class="btn btn-secondary" @click="showAll()">Show all activity</button>
                </x-empty-state>
            </div>
            <div x-show="! subject && ! typeFilter">
                <x-empty-state title="No activity yet" text="Send an order or sell something in the POS Simulator and it shows up here." icon="activity">
                    <a class="btn btn-primary" href="/purchase-orders">Purchase orders</a>
                    <a class="btn btn-secondary" href="/pos">POS Simulator</a>
                </x-empty-state>
            </div>
        </div>

        <ol class="timeline" x-show="visible().length > 0" x-cloak>
            <template x-for="entry in visible()" :key="entry._key">
                <li class="timeline-item">
                    <div class="timeline-top">
                        <span class="timeline-desc" x-text="entry.description"></span>
                        <span class="timeline-time" x-text="ago(entry)" :title="exact(entry)"></span>
                    </div>
                    <div class="timeline-meta">
                        <span class="pill" :data-tone="channel(entry).tone" x-text="channel(entry).label"></span>
                        <template x-if="subjectHref(entry)">
                            <a :href="subjectHref(entry)" x-text="subjectText(entry)"></a>
                        </template>
                        <template x-if="! subjectHref(entry) && subjectText(entry)">
                            <span x-text="subjectText(entry)"></span>
                        </template>
                        <button type="button" class="btn btn-ghost" x-show="changeList(entry).length > 0"
                                :aria-expanded="open[entry._key] ? 'true' : 'false'" @click="toggle(entry)">
                            <span x-text="open[entry._key] ? 'Hide changes' : 'Show changes'"></span>
                        </button>
                    </div>
                    <dl class="timeline-changes" x-show="open[entry._key]" x-cloak>
                        <template x-for="change in changeList(entry)" :key="change.field">
                            <div>
                                <dt x-text="change.field + ': '"></dt>
                                <dd x-text="change.from + ' to ' + change.to"></dd>
                            </div>
                        </template>
                    </dl>
                </li>
            </template>
        </ol>

        <div class="list-end" x-show="list.hasMore" x-cloak x-init="Patty.watchEnd($el, list)">
            <button type="button" class="btn btn-secondary" :disabled="list.loadingMore" :aria-busy="list.loadingMore" @click="list.more().catch(() => {})">
                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span> Load more
            </button>
        </div>
    </section>
</div>
@endsection
