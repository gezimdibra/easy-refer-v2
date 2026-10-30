{{-- The preview poster (H3): the artwork carries its preview
     line on its face, and its code is the INERT preview code —
     it opens this claim page only (H4). --}}
<div class="poster">
    <p class="art-line" data-copy="C09">{{ \App\Support\ClaimableCopy::c09() }}</p>
    <h2>{{ $page->shop_name }}</h2>
    <p>{{ $page->starter_reward_text }}</p>
    <p>Share with a friend — you both get {{ $page->starter_reward_text }}</p>
    <img src="{{ route('claimable.qr', ['token' => $token]) }}" alt="Preview code for {{ $page->shop_name }}" width="180" height="180">
    <p>No app to install.</p>
</div>
