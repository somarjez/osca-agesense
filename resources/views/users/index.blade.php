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
                        $editBag = "editUser{$user->id}";
                        $roleOptions = ['admin' => 'Administrator', 'encoder' => 'Encoder', 'viewer' => 'Viewer'];
                    @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors" x-data="{ deleteOpen: false, editOpen: {{ $errors->hasBag($editBag) ? 'true' : 'false' }} }">
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
                                <button type="button" @click="editOpen = true"
                                        class="btn btn-ghost text-[11.5px] px-2.5 py-1.5">
                                    <x-heroicon-o-pencil class="w-3.5 h-3.5" /> Edit
                                </button>

                                {{-- Edit account modal (replaces the standalone edit page) --}}
                                <x-modal show="editOpen" title="Edit account" max-width="max-w-lg">
                                    <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-4 text-left">
                                        @csrf @method('PUT')

                                        <div>
                                            <label class="eyebrow block mb-1.5">Full Name</label>
                                            <input type="text" name="name"
                                                   value="{{ $errors->hasBag($editBag) ? old('name', $user->name) : $user->name }}"
                                                   class="form-input w-full @error('name', $editBag) border-critical-400 @enderror" required>
                                            @error('name', $editBag)<p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>@enderror
                                        </div>

                                        <div>
                                            <label class="eyebrow block mb-1.5">Email Address</label>
                                            <input type="email" name="email"
                                                   value="{{ $errors->hasBag($editBag) ? old('email', $user->email) : $user->email }}"
                                                   class="form-input w-full tnum @error('email', $editBag) border-critical-400 @enderror" required>
                                            @error('email', $editBag)<p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>@enderror
                                        </div>

                                        <div>
                                            <label class="eyebrow block mb-1.5">Role</label>
                                            <select name="role" class="form-select w-full @error('role', $editBag) border-critical-400 @enderror" required>
                                                @foreach ($roleOptions as $rk => $rlabel)
                                                <option value="{{ $rk }}"
                                                    {{ (($errors->hasBag($editBag) ? old('role', $role) : $role) === $rk) ? 'selected' : '' }}>
                                                    {{ $rlabel }}
                                                </option>
                                                @endforeach
                                            </select>
                                            @error('role', $editBag)<p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>@enderror
                                        </div>

                                        <div class="border-t border-paper-rule dark:border-[#2b3530] pt-4">
                                            <label class="eyebrow block mb-1.5">New Password
                                                <span class="text-ink-400 dark:text-[#4a5550] normal-case font-normal ml-1">(leave blank to keep current)</span>
                                            </label>
                                            <div class="relative" x-data="{ show: false }">
                                                <input :type="show ? 'text' : 'password'" name="password" autocomplete="new-password"
                                                       class="form-input w-full pr-11 @error('password', $editBag) border-critical-400 @enderror"
                                                       placeholder="Minimum 8 characters">
                                                <button type="button" @click="show = !show" tabindex="-1"
                                                        class="absolute top-1/2 right-3 -translate-y-1/2 text-ink-300 hover:text-ink-600 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-500/40 transition-colors"
                                                        :aria-label="show ? 'Hide password' : 'Show password'">
                                                    <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                                                    <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-4 h-4" />
                                                </button>
                                            </div>
                                            @error('password', $editBag)<p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>@enderror
                                        </div>

                                        <div>
                                            <label class="eyebrow block mb-1.5">Confirm New Password</label>
                                            <div class="relative" x-data="{ show: false }">
                                                <input :type="show ? 'text' : 'password'" name="password_confirmation" autocomplete="new-password"
                                                       class="form-input w-full pr-11" placeholder="Re-enter new password">
                                                <button type="button" @click="show = !show" tabindex="-1"
                                                        class="absolute top-1/2 right-3 -translate-y-1/2 text-ink-300 hover:text-ink-600 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-500/40 transition-colors"
                                                        :aria-label="show ? 'Hide password' : 'Show password'">
                                                    <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                                                    <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-4 h-4" />
                                                </button>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-end gap-3 pt-1">
                                            <button type="button" class="btn btn-ghost" @click="editOpen = false">Cancel</button>
                                            <button type="submit" class="btn btn-primary" data-loading="Saving">
                                                <x-heroicon-o-check class="w-3.5 h-3.5" /> Save changes
                                            </button>
                                        </div>
                                    </form>
                                </x-modal>
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
