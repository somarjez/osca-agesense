@extends('layouts.app')
@section('page-title', 'New Account')
@section('page-subtitle', 'Create a new system user account')

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
            <form method="POST" action="{{ route('users.store') }}" class="space-y-5"
                  x-data="{ submitting: false }" @submit="submitting = true">
                @csrf

                <div>
                    <label class="eyebrow block mb-1.5" for="name">Full Name</label>
                    <input id="name" type="text" name="name"
                           value="{{ old('name') }}"
                           class="form-input w-full @error('name') border-critical-400 @enderror"
                           placeholder="e.g. Maria Santos"
                           required autofocus>
                    @error('name')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="eyebrow block mb-1.5" for="email">Email Address</label>
                    <input id="email" type="email" name="email"
                           value="{{ old('email') }}"
                           class="form-input w-full @error('email') border-critical-400 @enderror"
                           placeholder="e.g. maria@osca.local"
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
                        <option value="" disabled {{ old('role') ? '' : 'selected' }}>Select a role…</option>
                        @foreach ($roles as $role)
                        <option value="{{ $role }}" {{ old('role') === $role ? 'selected' : '' }}>
                            {{ ['admin' => 'Administrator', 'encoder' => 'Encoder', 'viewer' => 'Viewer'][$role] ?? ucfirst($role) }}
                        </option>
                        @endforeach
                    </select>
                    @error('role')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>

                <div class="border-t border-paper-rule dark:border-[#2b3530] pt-5">
                    <label class="eyebrow block mb-1.5" for="password">Password</label>
                    <div class="relative" x-data="{ show: false }">
                        <input id="password" name="password" :type="show ? 'text' : 'password'"
                               class="form-input w-full pr-11 @error('password') border-critical-400 @enderror"
                               placeholder="Minimum 8 characters"
                               required>
                        <button type="button" @click="show = !show" tabindex="-1"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-ink-300 hover:text-ink-600 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-500/40 transition-colors"
                                :aria-label="show ? 'Hide password' : 'Show password'">
                            <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                            <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-4 h-4" />
                        </button>
                    </div>
                    @error('password')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="eyebrow block mb-1.5" for="password_confirmation">Confirm Password</label>
                    <div class="relative" x-data="{ show: false }">
                        <input id="password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'"
                               class="form-input w-full pr-11"
                               placeholder="Re-enter password"
                               required>
                        <button type="button" @click="show = !show" tabindex="-1"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-ink-300 hover:text-ink-600 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-500/40 transition-colors"
                                :aria-label="show ? 'Hide password' : 'Show password'">
                            <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                            <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="{{ route('users.index') }}" class="btn btn-ghost">Cancel</a>
                    <button type="submit" :disabled="submitting"
                            class="btn btn-primary disabled:opacity-60 disabled:cursor-not-allowed">
                        <template x-if="!submitting">
                            <span class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-user-plus class="w-3.5 h-3.5" />
                                Create Account
                            </span>
                        </template>
                        <template x-if="submitting">
                            <span class="inline-flex items-center gap-1.5">
                                <svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                Creating…
                            </span>
                        </template>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
