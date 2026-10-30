{{-- ID-05 (Amendment 48, REVIEW-01): the review ask block,
     ONE shared partial for both receipts (R5: the surfaces
     cannot drift apart again). Full-width secondary button,
     below everything else on the receipt; never a modal,
     never a sentiment step, never above the prime. The href
     is OUR click route (CTR countable), never Google
     directly. The template takes the business name ONLY, so
     it is structurally incapable of naming the reward. --}}
<a class="btn btn-secondary w-full mt-5" href="{{ $reviewClickUrl }}" rel="noopener">Leave {{ $business->name }} a Google review</a>
