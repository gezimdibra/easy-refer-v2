@props([
    'name',
    'id' => null,
    'label' => null,
    'autocomplete' => 'current-password',
    'required' => false,
    'help' => null,
    'describedby' => null,
    'bag' => null,
])

{{--
    The one password field (ui-ux-standards CP-04 and UX-10):
    every password entry in the product renders through this
    component, so the field, its error wiring, and the show and
    hide toggle can never drift apart between pages. The toggle
    is a real button at a 48px target with aria-pressed and an
    aria-label that names the action; the glyph pair (eye,
    eye-off) mirrors the pressed state so state never rides on
    color alone. Error text renders from the shared error bag at
    the "{id}-error" element, wired through aria-describedby
    with the optional help text at "{id}-help", and the input
    takes the shared .form-input error styling through
    aria-invalid, exactly as the hand-written fields did. A field
    whose form keeps its own error bag names it through the bag
    prop, so its errors never print under a sibling form that
    happens to carry a field of the same name (T1-F2).

    Usage:
        <x-password-input name="password" label="Password"
            autocomplete="current-password" required />

    Pass class="form-input-flow" (or any extra input classes)
    through the attribute bag; they merge after the base input
    classes. The field wrapper is a .field, matching the form
    rhythm of the hand-written fields it replaces. A field that
    is explained by an element of its own (the register rules
    list) names it through the describedby prop; those ids join
    the help and error ids in aria-describedby.
--}}
@php
    $id = $id ?? $name;
    // A field on a page hosting several forms can name its form's
    // error bag (T1-F2); its errors then read from that bag only,
    // never the shared default bag the sibling forms also read.
    $fieldErrors = $bag ? $errors->getBag($bag) : $errors;
    $hasError = $fieldErrors->has($name);
    $describedBy = collect([$help ? $id.'-help' : null, $describedby, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp
<div class="field" x-data="{ show: false }">
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}</label>
    @endif
    <div class="relative">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="password"
            :type="show ? 'text' : 'password'"
            autocomplete="{{ $autocomplete }}"
            @if ($required) required @endif
            aria-invalid="{{ $hasError ? 'true' : 'false' }}"
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->merge(['class' => 'form-input form-input-password']) }}>
        <button type="button" class="password-toggle" @click="show = ! show"
            :aria-pressed="show.toString()"
            :aria-label="show ? 'Hide password' : 'Show password'"
            aria-controls="{{ $id }}">
            <span x-show="! show"><x-icon name="eye" :size="20" /></span>
            <span x-show="show" x-cloak><x-icon name="eye-off" :size="20" /></span>
        </button>
    </div>
    @if ($help)
        <p class="form-help" id="{{ $id }}-help">{{ $help }}</p>
    @endif
    @if ($fieldErrors->has($name))
        <p class="form-error" id="{{ $id }}-error">{{ $fieldErrors->first($name) }}</p>
    @endif
</div>
