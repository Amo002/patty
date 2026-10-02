{{-- Guiding empty state (design.md rule 5): says what is missing and, in the slot, offers the next action. --}}
@props(['title', 'text' => null, 'icon' => 'info'])
<div {{ $attributes->class(['empty-state']) }}>
    <x-icon :name="$icon" class="empty-icon" />
    <h3>{{ $title }}</h3>
    @if ($text)
        <p>{{ $text }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="row">{{ $slot }}</div>
    @endif
</div>
