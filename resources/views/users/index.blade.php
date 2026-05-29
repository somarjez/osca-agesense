@extends('layouts.app')
@section('page-title', 'User Management')
@section('page-subtitle', 'Create, edit, and deactivate system accounts')

@section('content')
<div class="space-y-5">

    {{-- Header actions --}}
    <div class="flex items-center justify-between">
        <div class="text-[13px] text-ink-500">
            {{ $users->count() }} {{ Str::plural('account', $users->count()) }}
        </div>
        <a href="{{ route('users.create') }}" class="btn btn-primary">
            <x-heroicon-o-user-plus class="w-3.5 h-3.5" />
            New Account
        </a>
    </div>

    {{-- Table --}}
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Name</th>
                        <th class="th">Email</th>
                        <th class="th">Role</th>
                        <th class="th">Created</th>
                        <th class="th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                    @php
                        $role = $user->getRoleNames()->first() ?? 'none';
                        $roleBadge = match ($role) {
                            'admin'   => 'badge-low',
                            'encoder' => 'badge-info',
                            'viewer'  => 'badge-neutral',
                            default   => 'badge-neutral',
                        };
                        $roleLabel = ['admin' => 'Administrator', 'encoder' => 'Encoder', 'viewer' => 'Viewer'][$role] ?? ucfirst($role);
                        $isSelf = $user->id === auth()->id();
                    @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors" x-data="{ deleteOpen: false }">
                        <td class="td">
                            <div class="flex items-center gap-2.5">
                                <div class="w-7 h-7 rounded-full bg-forest-200 dark:bg-forest-900/60 text-forest-800 dark:text-forest-300 grid place-items-center font-semibold text-xs flex-shrink-0">
                                    {{ strtoupper(substr($user->name, 0, 2)) }}
                                </div>
                                <span class="font-medium text-ink-900 dark:text-[#e4e1d8]">
                                    {{ $user->name }}
                                    @if ($isSelf)
                                    <span class="ml-1 text-[10px] text-ink-400 dark:text-[#6b7570]">(you)</span>
                                    @endif
                                </span>
                            </div>
                        </td>
                        <td class="td text-ink-600 dark:text-[#8a9087] tnum">{{ $user->email }}</td>
                        <td class="td">
                            <span class="badge {{ $roleBadge }}">{{ $roleLabel }}</span>
                        </td>
                        <td class="td text-[11.5px] text-ink-400 dark:text-[#6b7570] tnum whitespace-nowrap">
                            {{ $user->created_at->format('M d, Y') }}
                        </td>
                        <td class="td text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <a href="{{ route('users.edit', $user) }}"
                                   class="btn btn-ghost text-[11.5px] px-2.5 py-1.5">
                                    <x-heroicon-o-pencil class="w-3.5 h-3.5" /> Edit
                                </a>
                                @if (! $isSelf)
                                <button @click="deleteOpen = true"
                                        class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 text-critical-700 hover:text-critical-900 hover:bg-critical-50">
                                    <x-heroicon-o-trash class="w-3.5 h-3.5" /> Delete
                                </button>
                                <form x-ref="deleteForm" method="POST" action="{{ route('users.destroy', $user) }}" class="hidden">
                                    @csrf @method('DELETE')
                                </form>
                                {{-- Delete confirmation modal --}}
                                <x-confirm-modal show="deleteOpen"
                                                 title="Delete account?"
                                                 confirm="$refs.deleteForm.submit()"
                                                 confirm-label="Delete account">
                                    The account for <span class="font-semibold text-ink-900 dark:text-[#e4e1d8]">{{ $user->name }}</span> will be permanently deleted. This cannot be undone.
                                </x-confirm-modal>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-16 text-center text-ink-400 dark:text-[#6b7570]">No accounts found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Role legend --}}
    <div class="card card-body">
        <div class="eyebrow mb-3">Role Permissions</div>
        <div class="grid grid-cols-3 gap-4 text-[12.5px]">
            <div>
                <div class="mb-1.5"><span class="badge badge-low">Administrator</span></div>
                <ul class="space-y-0.5 text-ink-500">
                    <li>✓ Full access to all features</li>
                    <li>✓ Create / edit / delete seniors</li>
                    <li>✓ Archive and restore records</li>
                    <li>✓ Run ML batch and single inference</li>
                    <li>✓ Activity log, exports, snapshots</li>
                    <li>✓ Manage user accounts</li>
                </ul>
            </div>
            <div>
                <div class="mb-1.5"><span class="badge badge-info">Encoder</span></div>
                <ul class="space-y-0.5 text-ink-500">
                    <li>✓ Create and edit senior profiles</li>
                    <li>✓ Manage QoL surveys</li>
                    <li>✓ Assign / update recommendations</li>
                    <li>✓ Run ML inference</li>
                    <li>✓ View all reports</li>
                    <li>✗ Cannot delete, archive, export</li>
                </ul>
            </div>
            <div>
                <div class="mb-1.5"><span class="badge badge-neutral">Viewer</span></div>
                <ul class="space-y-0.5 text-ink-500">
                    <li>✓ View dashboard and all reports</li>
                    <li>✓ View senior profiles</li>
                    <li>✓ View recommendations</li>
                    <li>✗ Cannot create or edit anything</li>
                    <li>✗ No access to ML tools</li>
                    <li>✗ No access to administration</li>
                </ul>
            </div>
        </div>
    </div>

</div>
@endsection
