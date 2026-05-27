@extends('layouts.app')
@section('page-title', 'Edit Account')
@section('page-subtitle', $user->name)

@section('content')
<div class="max-w-lg">
    <div class="card">
        <div class="card-head">
            <div class="card-title">Account Details</div>
            <a href="{{ route('users.index') }}" class="btn btn-ghost text-xs">
                <x-heroicon-o-arrow-left class="w-3.5 h-3.5" />
                Back
            </a>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-5"
                  x-data="{ submitting: false }" @submit="submitting = true">
                @csrf
                @method('PUT')

                <div>
                    <label class="eyebrow block mb-1.5" for="name">Full Name</label>
                    <input id="name" type="text" name="name"
                           value="{{ old('name', $user->name) }}"
                           class="form-input w-full @error('name') border-critical-400 @enderror"
                           required autofocus>
                    @error('name')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="eyebrow block mb-1.5" for="email">Email Address</label>
                    <input id="email" type="email" name="email"
                           value="{{ old('email', $user->email) }}"
                           class="form-input w-full @error('email') border-critical-400 @enderror"
                           required>
                    @error('email')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="eyebrow block mb-1.5" for="role">Role</label>
                    <select id="role" name="role"
                            class="form-select w-full @error('role') border-critical-400 @enderror"
                            required>
                        @foreach ($roles as $role)
                        <option value="{{ $role }}" {{ (old('role', $currentRole) === $role) ? 'selected' : '' }}>
                            {{ ['admin' => 'Administrator', 'encoder' => 'Encoder', 'viewer' => 'Viewer'][$role] ?? ucfirst($role) }}
                        </option>
                        @endforeach
                    </select>
                    @error('role')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>

                <div class="border-t border-paper-rule dark:border-[#2b3530] pt-5">
                    <label class="eyebrow block mb-1.5" for="password">
                        New Password
                        <span class="text-ink-400 dark:text-[#4a5550] normal-case font-normal ml-1">(leave blank to keep current)</span>
                    </label>
                    <input id="password" type="password" name="password"
                           class="form-input w-full @error('password') border-critical-400 @enderror"
                           placeholder="Minimum 8 characters">
                    @error('password')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="eyebrow block mb-1.5" for="password_confirmation">Confirm New Password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           class="form-input w-full"
                           placeholder="Re-enter new password">
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="{{ route('users.index') }}" class="btn btn-ghost">Cancel</a>
                    <button type="submit" :disabled="submitting"
                            class="btn btn-primary disabled:opacity-60 disabled:cursor-not-allowed">
                        <template x-if="!submitting">
                            <span class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-check class="w-3.5 h-3.5" />
                                Save Changes
                            </span>
                        </template>
                        <template x-if="submitting">
                            <span class="inline-flex items-center gap-1.5">
                                <svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                Saving…
                            </span>
                        </template>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
