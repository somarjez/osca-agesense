@extends('layouts.app')
@section('page-title', 'User Management')
@section('page-subtitle', 'Create, edit, and deactivate system accounts')

@section('content')
<div class="space-y-5">

    {{-- Header actions --}}
    <div class="flex items-center justify-between">
        <div class="text-[13px] text-ink-500 dark:text-[#6b7570]">
            {{ $users->count() }} {{ Str::plural('account', $users->count()) }}
        </div>
        <a href="{{ route('users.create') }}" class="btn btn-primary">
            <x-heroicon-o-user-plus class="w-3.5 h-3.5" />
            New Account
        </a>
    </div>

    {{-- Table --}}
    <div class="card">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-paper-rule dark:border-[#2b3530] text-[11px] tracking-[0.08em] uppercase text-ink-400 dark:text-[#4a5550]">
                        <th class="px-5 py-3 text-left font-semibold">Name</th>
                        <th class="px-5 py-3 text-left font-semibold">Email</th>
                        <th class="px-5 py-3 text-left font-semibold">Role</th>
                        <th class="px-5 py-3 text-left font-semibold">Created</th>
                        <th class="px-5 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                    @forelse ($users as $user)
                    @php
                        $role = $user->getRoleNames()->first() ?? 'none';
                        $roleStyle = match ($role) {
                            'admin'   => 'bg-forest-100 text-forest-800 dark:bg-forest-900/40 dark:text-forest-300',
                            'encoder' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
                            'viewer'  => 'bg-ink-100 text-ink-600 dark:bg-[#2b3530] dark:text-[#9ba5a0]',
                            default   => 'bg-ink-100 text-ink-500',
                        };
                        $roleLabel = ['admin' => 'Administrator', 'encoder' => 'Encoder', 'viewer' => 'Viewer'][$role] ?? ucfirst($role);
                        $isSelf = $user->id === auth()->id();
                    @endphp
                    <tr class="hover:bg-paper-2/50 dark:hover:bg-[#1a201d]/50 transition-colors">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2.5">
                                <div class="w-7 h-7 rounded-full bg-forest-200 dark:bg-forest-900/60 text-forest-800 dark:text-forest-300 grid place-items-center font-semibold text-xs flex-shrink-0">
                                    {{ strtoupper(substr($user->name, 0, 2)) }}
                                </div>
                                <span class="font-medium text-ink-900 dark:text-[#e4e1d8]">
                                    {{ $user->name }}
                                    @if ($isSelf)
                                    <span class="ml-1 text-[10px] text-ink-400 dark:text-[#4a5550]">(you)</span>
                                    @endif
                                </span>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-ink-600 dark:text-[#9ba5a0] tabular-nums">{{ $user->email }}</td>
                        <td class="px-5 py-3">
                            <span class="inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full {{ $roleStyle }}">
                                {{ $roleLabel }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-ink-400 dark:text-[#4a5550] text-xs tabular-nums whitespace-nowrap">
                            {{ $user->created_at->format('M d, Y') }}
                        </td>
                        <td class="px-5 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('users.edit', $user) }}"
                                   class="btn btn-ghost px-2.5 py-1.5 text-xs">
                                    <x-heroicon-o-pencil class="w-3.5 h-3.5" />
                                    Edit
                                </a>
                                @if (! $isSelf)
                                <form method="POST" action="{{ route('users.destroy', $user) }}"
                                      onsubmit="return confirm('Delete the account for {{ addslashes($user->name) }}? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost px-2.5 py-1.5 text-xs text-red-600 hover:text-red-700 dark:text-red-400">
                                        <x-heroicon-o-trash class="w-3.5 h-3.5" />
                                        Delete
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-ink-400 dark:text-[#4a5550]">No accounts found.</td>
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
                <div class="font-semibold text-ink-900 dark:text-[#e4e1d8] mb-1.5">
                    <span class="inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full bg-forest-100 text-forest-800 dark:bg-forest-900/40 dark:text-forest-300 mr-1.5">Administrator</span>
                </div>
                <ul class="space-y-0.5 text-ink-500 dark:text-[#6b7570]">
                    <li>✓ Full access to all features</li>
                    <li>✓ Create / edit / delete seniors</li>
                    <li>✓ Archive and restore records</li>
                    <li>✓ Run ML batch and single inference</li>
                    <li>✓ Activity log, exports, snapshots</li>
                    <li>✓ Manage user accounts</li>
                </ul>
            </div>
            <div>
                <div class="font-semibold text-ink-900 dark:text-[#e4e1d8] mb-1.5">
                    <span class="inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300 mr-1.5">Encoder</span>
                </div>
                <ul class="space-y-0.5 text-ink-500 dark:text-[#6b7570]">
                    <li>✓ Create and edit senior profiles</li>
                    <li>✓ Manage QoL surveys</li>
                    <li>✓ Assign / update recommendations</li>
                    <li>✓ Run ML inference</li>
                    <li>✓ View all reports</li>
                    <li>✗ Cannot delete, archive, export</li>
                </ul>
            </div>
            <div>
                <div class="font-semibold text-ink-900 dark:text-[#e4e1d8] mb-1.5">
                    <span class="inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full bg-ink-100 text-ink-600 dark:bg-[#2b3530] dark:text-[#9ba5a0] mr-1.5">Viewer</span>
                </div>
                <ul class="space-y-0.5 text-ink-500 dark:text-[#6b7570]">
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
