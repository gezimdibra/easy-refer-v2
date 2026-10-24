{{--
    The QR card's two poster rows (D-9, release 08b): true rows,
    full width, label left, the arrow adjacent to the label and
    held at its declared size with flex-none (they rendered
    squashed at 12 by 16 and 14 by 16 against a 16px declaration,
    and the pair wrapped mid-phrase on a 360px phone), a divider
    between the rows, and a 48px standing height. Shared by the
    card's full and collapsed forms.
--}}
<ul class="mt-3 divide-y divide-line border-t border-line">
    <li>
        <a class="flex min-h-12 items-center gap-1.5 font-semibold text-brand-700 hover:underline" href="{{ route('owner.qr') }}#posters">Download the on-screen poster <x-icon name="arrow-up-right" :size="16" class="flex-none" /></a>
    </li>
    <li>
        <a class="flex min-h-12 items-center gap-1.5 font-semibold text-brand-700 hover:underline" href="{{ route('owner.qr') }}#how-redemption-works">See how redemption works <x-icon name="arrow-up-right" :size="16" class="flex-none" /></a>
    </li>
</ul>
