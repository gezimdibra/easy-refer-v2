@extends('layouts.admin')

@section('title', 'Audit log')

@section('content')
    <h1 class="text-[28px] font-bold">Audit log</h1>

    <form method="GET" action="{{ route('admin.audit') }}" class="card mt-4">
        <label for="action">Filter by action</label>
        <div class="flex gap-2">
            <input id="action" name="action" type="search" value="{{ $action }}" class="flex-1">
            <button type="submit" class="btn btn-secondary">Filter</button>
        </div>
    </form>

    <div class="card mt-4">
        @if ($entries->isEmpty())
            @if ($action !== '')
                <p class="text-text-2">No audit entries match.</p>
            @else
                <p class="text-text-2">No audit entries yet.</p>
            @endif
        @else
            <table class="w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">When</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Action</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Actor</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Business</th>
                        <th scope="col" class="py-2 font-semibold">Target</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $entry->created_at->format('M j, Y, g:i A') }}</td>
                            <td class="py-2 pr-3">{{ \App\Support\AuditActionLabel::for($entry->action) }}</td>
                            <td class="py-2 pr-3">{{ $entry->user?->email ?? 'System' }}</td>
                            <td class="py-2 pr-3">{{ $entry->business?->name }}</td>
                            <td class="py-2">{{ $entry->target_type ? class_basename($entry->target_type).' #'.$entry->target_id : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3">{{ $entries->links() }}</div>
        @endif
    </div>
@endsection
