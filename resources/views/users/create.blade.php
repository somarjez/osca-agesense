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
            <form method="POST" action="{{ route('users.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label class="eyebrow block mb-1.5" for="name">Full Name</label>
                    <input id="name" type="text" name="name"
                           value="{{ old('name') }}"
                           class="form-input w-full @error('name') border-red-400 @enderror"
                           placeholder="e.g. Maria Santos"
                           required autofocus>
                    @error('name')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="eyebrow block mb-1.5" for="email">Email Address</label>
                    <input id="email" type="email" name="email"
                           value="{{ old('email') }}"
                           class="form-input w-full @error('email') border-red-400 @enderror"
                           placeholder="e.g. maria@osca.local"
                           required>
                    @error('email')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="eyebrow block mb-1.5" for="role">Role</label>
                    <select id="role" name="role"
                            class="form-select w-full @error('role') border-red-400 @enderror"
                            required>
                        <option value="" disabled {{ old('role') ? '' : 'selected' }}>Select a role…</option>
                        @foreach ($roles as $role)
                        <option value="{{ $role }}" {{ old('role') === $role ? 'selected' : '' }}>
                            {{ ['admin' => 'Administrator', 'encoder' => 'Encoder', 'viewer' => 'Viewer'][$role] ?? ucfirst($role) }}
                        </option>
                        @endforeach
                    </select>
                    @error('role')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="border-t border-paper-rule dark:border-[#2b3530] pt-5">
                    <label class="eyebrow block mb-1.5" for="password">Password</label>
                    <input id="password" type="password" name="password"
                           class="form-input w-full @error('password') border-red-400 @enderror"
                           placeholder="Minimum 8 characters"
                           required>
                    @error('password')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="eyebrow block mb-1.5" for="password_confirmation">Confirm Password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           class="form-input w-full"
                           placeholder="Re-enter password"
                           required>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="{{ route('users.index') }}" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <x-heroicon-o-user-plus class="w-3.5 h-3.5" />
                        Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
