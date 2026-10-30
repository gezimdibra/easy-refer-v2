{{--
    The phone preview (R2 CAMP-COMPOSE, design section 2): the
    rendered campaign text inside a phone-shaped frame, so the
    owner reads the message the way a customer receives it.
    Shared by compose (live Alpine binding), confirm, and the
    detail page. Pass $alpine=true on compose, where the text
    binds to the form's rendered figure; otherwise $text is the
    server-rendered body.
--}}
<div data-phone-preview class="mx-auto w-full max-w-[300px] rounded-[2rem] border border-line-strong bg-surface-2 p-3 shadow-e1">
    <div class="rounded-[1.5rem] bg-surface-0 px-4 py-5">
        <div class="rounded-2xl rounded-tl-md bg-surface-1 px-3 py-2 text-text-1">
            @if ($alpine ?? false)
                <p x-text="rendered"></p>
            @else
                <p>{{ $text }}</p>
            @endif
        </div>
    </div>
</div>
