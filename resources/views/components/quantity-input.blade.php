{{--
    Quantity field with a g/kg or ml/L switch (D-038). Everything it hands to the page is an integer in base units.
    Bind it with x-model:  <x-quantity-input unit="g" context="purchase" label="Beef" x-model="line.quantity" />
    Pieces get no switch. The conversion and the precision error come from public/js/units.js.
    With `name` it also writes a hidden input with the base value, so a plain form post works too.
--}}
@props(['unit', 'context' => 'purchase', 'label' => null, 'name' => null, 'value' => null, 'hint' => null, 'id' => null])
@php
    $id = $id ?? 'qty-'.\Illuminate\Support\Str::random(6);
@endphp
<div {{ $attributes->class(['field']) }}
     x-data="quantityInput({{ \Illuminate\Support\Js::from(['unit' => $unit, 'context' => $context, 'value' => $value]) }})"
     x-modelable="base">
    @if ($label)
        <label for="{{ $id }}">{{ $label }}</label>
    @endif
    <div class="qty" :data-invalid="error !== ''">
        <input id="{{ $id }}" class="qty-field" type="text" inputmode="decimal" autocomplete="off"
               x-model="text" @input="onInput()" :aria-invalid="error !== ''" aria-describedby="{{ $id }}-msg">
        @if ($unit === 'piece')
            <span class="qty-suffix">pcs</span>
        @else
            <div class="segmented" role="group" aria-label="Unit">
                <template x-for="m in modes" :key="m">
                    <button type="button" :aria-pressed="m === mode" @click="setMode(m)" x-text="label(m)"></button>
                </template>
            </div>
        @endif
    </div>
    <div id="{{ $id }}-msg">
        <p class="inline-error" x-show="error" x-text="error" role="alert"></p>
        @if ($hint)
            <p class="hint">{{ $hint }}</p>
        @endif
    </div>
    @if ($name)
        <input type="hidden" name="{{ $name }}" :value="base === null ? '' : base">
    @endif
</div>
