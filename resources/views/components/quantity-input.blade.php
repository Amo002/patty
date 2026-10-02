{{--
    Quantity field with a g/kg or ml/L switch (D-038). Everything it hands to the page is an integer in base units.
    Bind it with x-model:  <x-quantity-input unit="g" context="purchase" label="Beef" x-model="line.quantity" />
    Pieces get no switch. The conversion and the precision error come from public/js/units.js.
    With `name` it also writes a hidden input with the base value, so a plain form post works too.
    `allow-zero` accepts 0 for fields where zero is meaningful; by default 0 and negatives are refused before submit.

    Ids: x-id gives every instance its own Alpine id scope, so inside an x-for each row gets its own
    label `for` and aria-describedby (a Blade-time random id would be identical in every row).
    Pass `id` to use a fixed one instead.

    Events: `quantity-change` carries { base, error, mode }. `mode` is the unit the user typed in, for
    re-expressing a server 422 with Patty.fieldErrors.
--}}
@props(['unit', 'context' => 'purchase', 'label' => null, 'name' => null, 'value' => null, 'hint' => null, 'id' => null, 'allowZero' => false])
<div {{ $attributes->class(['field']) }}
     x-id="['qty']"
     x-data="quantityInput({{ \Illuminate\Support\Js::from(['unit' => $unit, 'context' => $context, 'value' => $value, 'allowZero' => (bool) $allowZero]) }})"
     x-modelable="base">
    @if ($label)
        <label @if ($id) for="{{ $id }}" @else :for="$id('qty')" @endif>{{ $label }}</label>
    @endif
    <div class="qty" :data-invalid="error !== ''">
        <input @if ($id) id="{{ $id }}" aria-describedby="{{ $id }}-msg" @else :id="$id('qty')" :aria-describedby="$id('qty') + '-msg'" @endif
               class="qty-field" type="text" inputmode="decimal" autocomplete="off"
               x-model="text" @input="onInput()" :aria-invalid="error !== ''">
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
    <div @if ($id) id="{{ $id }}-msg" @else :id="$id('qty') + '-msg'" @endif>
        <p class="inline-error" x-show="error" x-text="error" role="alert"></p>
        @if ($hint)
            <p class="hint">{{ $hint }}</p>
        @endif
    </div>
    @if ($name)
        <input type="hidden" name="{{ $name }}" :value="base === null ? '' : base">
    @endif
</div>
