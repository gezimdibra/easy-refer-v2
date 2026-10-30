@extends('layouts.app')

@section('title', 'Batch claims')

@section('content')
    {{-- One batch's claims (ID-11, Amendment 53): the rows the
         survival metric reads, with the owner's one claim-level
         act, void with a reason (state C10). Phones render
         masked per the shipped conventions. --}}
    <h1 class="type-title-2 text-text-1">{{ $batch->label }}</h1>
    <p class="type-body mt-2 text-text-2">{{ $batch->offer_text }}</p>

    @if (session('status'))
        <p class="status status-success mt-4" role="status">{{ session('status') }}</p>
    @endif

    @if ($claims->isEmpty())
        <p class="type-body mt-4 text-text-2">No claims yet.</p>
    @else
        <ul class="mt-4">
            @foreach ($claims as $claim)
                <li class="border-line {{ $loop->first ? '' : 'border-t' }}" style="padding: 12px 0" data-claim="{{ $claim->id }}">
                    <p class="type-body text-text-1">{{ $claim->customer?->first_name }} · {{ \App\Support\PhoneNumber::mask($claim->customer?->phone_e164 ?? '') }}</p>
                    <p class="type-body text-text-2">Claimed {{ $business->localTime($claim->created_at)->format('F j, Y') }}
                        @if ($claim->state === 'redeemed')
                            · Redeemed {{ $business->localTime($claim->redeemed_at)->format('F j, Y') }}
                        @elseif ($claim->state === 'expired')
                            · Expired {{ $business->localTime($claim->expires_at)->format('F j, Y') }}
                        @elseif ($claim->state === 'voided')
                            · Cancelled
                        @else
                            · Good until {{ $business->localTime($claim->expires_at)->format('F j, Y') }}
                        @endif
                    </p>
                    @if ($claim->state === 'ready')
                        <form method="POST" action="{{ route('owner.qr.coupons.claims.void', ['batch' => $batch, 'claim' => $claim]) }}" class="mt-2 flex flex-wrap items-center gap-2" data-loading>
                            @csrf
                            <label class="form-label" for="reason-{{ $claim->id }}">Cancel this coupon</label>
                            <select name="reason" id="reason-{{ $claim->id }}" class="form-input form-input-app" style="width: auto">
                                <option value="owner_test">It was my own test</option>
                                <option value="lost_phone">The phone was lost</option>
                                <option value="other">Another reason</option>
                            </select>
                            <button type="submit" class="btn btn-secondary">Cancel coupon</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <p class="mt-4">
        <a class="btn btn-secondary" href="{{ route('owner.qr.coupons') }}">Back to Coupons</a>
    </p>
@endsection
