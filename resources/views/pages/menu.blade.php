@extends('layouts.app')

@section('title', 'Menu & Recipes')

@section('actions')
    {{-- The header sits outside the page component, so the button talks to it with a window event. --}}
    <button type="button" class="btn btn-primary" @click="$dispatch('menu-item-create')">
        <x-icon name="plus" class="icon-sm" /> Add menu item
    </button>
@endsection

@section('content')
    @include('pages.catalogue.assets', ['script' => 'menu'])

    <div x-data="menuPage" @menu-item-create.window="openCreate()" class="stack">

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
            <x-empty-state title="No menu items yet" text="Add what you sell, then tell Patty what goes into each one. Sales take their stock from the recipe." icon="menu">
                <button type="button" class="btn btn-primary" @click="openCreate()"><x-icon name="plus" class="icon-sm" /> Add menu item</button>
            </x-empty-state>
        </div>

        {{-- Skeleton cards have the same shape as real ones (U1). --}}
        <div class="menu-grid" x-show="status === 'loading'" aria-hidden="true">
            <template x-for="n in 6" :key="'sk' + n">
                <div class="card menu-card">
                    <div class="skeleton skeleton-block menu-photo"></div>
                    <div class="card-body stack stack-sm">
                        <div class="skeleton skeleton-line w-50"></div>
                        <div class="skeleton skeleton-line w-75"></div>
                    </div>
                </div>
            </template>
        </div>

        <div class="menu-grid" x-show="status === 'loaded'" x-cloak>
            <template x-for="item in items" :key="item.id">
                <article class="card menu-card" :class="{ 'row-changed': changedId === item.id }">
                    <x-photo x-bind:src="item.image_url" ratio="16 / 10" />
                    <div class="card-body stack stack-sm">
                        <div class="row row-between">
                            <h2 class="menu-name" x-text="item.name"></h2>
                            <span class="pill" :data-tone="item.is_sellable ? 'ok' : 'neutral'" x-text="item.is_sellable ? 'Sellable' : 'No recipe yet'"></span>
                        </div>
                        <ul class="recipe-summary" x-show="item.recipe.length">
                            <template x-for="line in item.recipe" :key="line.ingredient.id">
                                <li class="tabular" x-text="recipeText(line)"></li>
                            </template>
                        </ul>
                        <p class="muted text-sm" x-show="!item.recipe.length">Add the ingredients it uses so it can be sold.</p>
                        <div>
                            <button type="button" class="btn btn-secondary" @click="openEdit(item)" :aria-label="'Edit recipe for ' + item.name">
                                <x-icon name="edit" class="icon-sm" /> Edit recipe
                            </button>
                        </div>
                    </div>
                </article>
            </template>
        </div>

        {{-- Lazy loading: the sentinel loads the next page near the viewport; the button is the keyboard fallback (U2). --}}
        <div class="row load-more" x-show="status === 'loaded' && hasMore" x-cloak>
            <button type="button" class="btn btn-secondary" :disabled="loadingMore" :aria-busy="loadingMore" @click="loadMore()">
                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                <span x-text="moreError ? 'Could not load more. Try again' : 'Load more'"></span>
            </button>
        </div>
        <div x-init="watchEnd($el)" aria-hidden="true"></div>

        {{-- Create an item, or edit its name and recipe --}}
        <dialog class="dialog dialog-wide" x-ref="dialog" aria-labelledby="menu-form-title" @cancel="busy && $event.preventDefault()">
            <form @submit.prevent="save()" novalidate>
                <div class="dialog-head"><h2 id="menu-form-title" x-text="form.id ? 'Edit recipe' : 'Add menu item'"></h2></div>
                <div class="dialog-body stack">
                    <div class="field">
                        <label for="menu-name">Name</label>
                        <input id="menu-name" class="input" x-model="form.name" maxlength="100" autocomplete="off" required
                               placeholder="Classic Burger" :aria-invalid="err('name') !== ''" aria-describedby="menu-name-msg">
                        <p id="menu-name-msg" class="inline-error" role="alert" x-show="err('name')"><x-icon name="alert" /> <span x-text="err('name')"></span></p>
                    </div>

                    <div class="stack stack-sm">
                        <div class="row row-between">
                            <h3>Recipe <span class="muted" x-show="!form.id">(optional)</span></h3>
                            <button type="button" class="btn btn-secondary" @click="addLine()"><x-icon name="plus" class="icon-sm" /> Add ingredient</button>
                        </div>
                        <p class="hint">What one serving uses. Each ingredient can appear once. Changing a recipe applies to future sales only.</p>

                        <p class="muted text-sm" x-show="form.lines.length === 0">No ingredients yet. Without a recipe this item cannot be sold from the POS.</p>

                        <template x-for="(line, index) in form.lines" :key="line.key">
                            <div class="recipe-line">
                                <div class="field combo" @click.outside="line.open && closeCombo(line)">
                                    <label :for="'recipe-ing-' + line.key">Ingredient</label>
                                    <input :id="'recipe-ing-' + line.key" class="input" role="combobox" autocomplete="off" placeholder="Search ingredients"
                                           aria-autocomplete="list" :aria-expanded="line.open" :aria-controls="'recipe-list-' + line.key"
                                           :aria-activedescendant="line.open ? 'recipe-opt-' + line.key + '-' + line.active : null"
                                           x-model="line.search"
                                           @focus="line.open = true; line.active = 0"
                                           @input="line.open = true; line.active = 0"
                                           @keydown="comboKey($event, line)">
                                    <ul class="combo-list" role="listbox" :id="'recipe-list-' + line.key" x-show="line.open" x-cloak>
                                        <li class="combo-note" x-show="ingredientsStatus === 'loading'">Loading ingredients...</li>
                                        <li class="combo-note" x-show="ingredientsStatus === 'error'">
                                            Could not load ingredients.
                                            <button type="button" class="btn btn-ghost" @mousedown.prevent="loadIngredients()">Try again</button>
                                        </li>
                                        <li class="combo-note" x-show="ingredientsStatus === 'ready' && ingredients.length === 0">
                                            No ingredients yet. <a href="/ingredients">Add some first.</a>
                                        </li>
                                        <li class="combo-note" x-show="ingredientsStatus === 'ready' && ingredients.length > 0 && options(line).length === 0">Nothing matches that.</li>
                                        <template x-for="(option, i) in options(line)" :key="option.id">
                                            <li role="option" :id="'recipe-opt-' + line.key + '-' + i" class="combo-option"
                                                :class="{ 'is-active': i === line.active, 'is-used': usedElsewhere(option, line) }"
                                                :aria-selected="line.ingredient !== null && line.ingredient.id === option.id"
                                                :aria-disabled="usedElsewhere(option, line)"
                                                @mousedown.prevent="pick(line, option)" @mousemove="line.active = i">
                                                <span x-text="option.name"></span>
                                                <span class="muted text-xs" x-text="usedElsewhere(option, line) ? 'already in this recipe' : (option.unit_label || option.unit)"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>

                                <div class="recipe-qty">
                                    {{-- The quantity input fixes its unit when it renders, so one copy exists per unit and x-if picks the one that matches the chosen ingredient. --}}
                                    @foreach (['g', 'ml', 'piece'] as $unit)
                                        <template x-if="line.ingredient && line.ingredient.unit === '{{ $unit }}'">
                                            <x-quantity-input unit="{{ $unit }}" context="recipe" label="Quantity"
                                                              x-model="line.quantity"
                                                              @quantity-change="line.mode = $event.detail.mode; line.qtyError = $event.detail.error; line.error = ''" />
                                        </template>
                                    @endforeach
                                    <p class="muted text-sm recipe-qty-placeholder" x-show="!line.ingredient">Pick an ingredient first.</p>
                                </div>

                                <button type="button" class="btn btn-ghost btn-icon recipe-remove" aria-label="Remove this ingredient" @click="removeLine(line)">
                                    <x-icon name="trash" />
                                </button>

                                <p class="inline-error recipe-line-error" role="alert" x-show="lineMessage(index)"><x-icon name="alert" /> <span x-text="lineMessage(index)"></span></p>
                            </div>
                        </template>

                        <template x-for="message in generalErrors()" :key="message">
                            <p class="inline-error" role="alert"><x-icon name="alert" /> <span x-text="message"></span></p>
                        </template>
                    </div>
                </div>
                <div class="dialog-foot">
                    <button type="button" class="btn btn-secondary" :disabled="busy" @click="closeForm()">Cancel</button>
                    <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">
                        <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span x-text="form.id ? 'Save recipe' : 'Add menu item'"></span>
                    </button>
                </div>
            </form>
        </dialog>
    </div>
@endsection
