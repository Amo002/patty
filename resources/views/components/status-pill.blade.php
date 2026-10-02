{{--
    A labelled status pill: dot plus text, never colour alone (design.md principle 2).
    The same map exists in public/js/ui.js (Patty.statusMeta) for pills rendered by Alpine templates.
    `short` is a closed order that still had quantity outstanding; `negative` is a stock balance below zero.
--}}
@props(['status', 'shortClosed' => false])
@php
    $map = [
        'draft' => ['Draft', 'neutral'],
        'sent' => ['Sent', 'info'],
        'received' => ['Partially received', 'warn'],
        'closed' => ['Closed', 'ok'],
        'short' => ['Short', 'neutral'],
        'negative' => ['Negative', 'danger'],
    ];
    $key = $status === 'closed' && $shortClosed ? 'short' : $status;
    [$label, $tone] = $map[$key] ?? [(string) $status, 'neutral'];
@endphp
<span {{ $attributes->class(['pill']) }} data-tone="{{ $tone }}">{{ $label }}</span>
