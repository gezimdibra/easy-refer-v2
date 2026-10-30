@props(['icon' => 'mail', 'headline'])
{{-- AU-5 (R5 pack A3): the shared status pattern for the auth
     utility screens: an icon medallion, one headline, the body,
     one action block, one quiet footer row. Built once, used by
     the verify gate (AU-4), the forgot-password sent state and
     the unlock screen (AU-8), so the three emptiest screens in
     the product share one grammar. --}}
<div class="auth-status">
    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-50 text-text-1" aria-hidden="true"><x-icon :name="$icon" :size="28" /></span>
    <h1 class="type-display-xs mt-5 text-text-1">{{ $headline }}</h1>
    {{ $slot }}
    @isset($actions)
        <div class="mt-6">{{ $actions }}</div>
    @endisset
    @isset($footer)
        <div class="mt-6 border-t border-line pt-4">{{ $footer }}</div>
    @endisset
</div>
