@props(['model' => 'pw'])
{{-- AU-6: the shared password-rules checklist (register step 3
     and the reset form render this one component, so the rules
     can never drift). AUTH-UP-03: both rules, stated as the
     inventory states them; the length rule ticks live against
     the Alpine model the host form binds its password field to. --}}
<ul class="mt-2 space-y-1.5" id="password-rules">
    <li class="type-caption flex items-center gap-2 text-text-2">
        <span x-show="{{ $model }}.length >= 12" x-cloak class="text-success-strong"><x-icon name="check" :size="16" /></span>
        <span x-show="{{ $model }}.length < 12" class="text-line-strong"><x-icon name="circle" :size="16" /></span>
        Use at least 12 characters.
    </li>
    <li class="type-caption flex items-center gap-2 text-text-2">
        <span class="text-line-strong"><x-icon name="circle" :size="16" /></span>
        Avoid passwords you have used anywhere else.
    </li>
</ul>
