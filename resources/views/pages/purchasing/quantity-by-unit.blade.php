{{--
    A quantity input whose unit follows an ingredient chosen at runtime.
    x-quantity-input takes `unit` at render time, so one copy per unit sits behind x-if and Alpine
    keeps only the one that matches. Pieces get no g/kg switch, g and ml start in kg and L (D-038).

    Variables: $model (x-model expression), $unit (expression giving g, ml, piece or '' while unset),
    $change (@quantity-change handler), $label, $allowZero, $class.
--}}
@php
    $label = $label ?? 'Quantity';
    $allowZero = $allowZero ?? false;
    $class = $class ?? '';
@endphp
@foreach (['g', 'ml', 'piece'] as $u)
    <template x-if="{{ $unit }} === '{{ $u }}'">
        <x-quantity-input :unit="$u" context="purchase" :label="$label" :allow-zero="$allowZero" :class="$class"
                          x-model="{{ $model }}" @quantity-change="{{ $change }}" />
    </template>
@endforeach
<template x-if="! {{ $unit }}">
    <div class="field {{ $class }}">
        <label>{{ $label }}</label>
        <div class="qty"><input class="qty-field" type="text" disabled placeholder="Pick an ingredient first" aria-label="{{ $label }}"></div>
    </div>
</template>
