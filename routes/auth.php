<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::middleware('guest')->group(function () {
	Route::get('/login', function () {
		// Bootstrap a local dev account if users table is empty.
		if (User::count() === 0) {
			User::create([
				'name' => 'OSCA Admin',
				'email' => 'admin@osca.local',
				'password' => Hash::make('password'),
			]);
		}

		return view('auth.login');
	})->name('login');

	Route::post('/login', function (Request $request) {
		$credentials = $request->validate([
			'email' => ['required', 'email'],
			'password' => ['required', 'string'],
		]);

		if (! Auth::attempt($credentials, $request->boolean('remember'))) {
			throw ValidationException::withMessages([
				'email' => __('auth.failed'),
			]);
		}

		$request->session()->regenerate();

		return redirect()->intended(route('dashboard', absolute: false));
	});
});

Route::post('/logout', function (Request $request) {
	Auth::logout();

	$request->session()->invalidate();
	$request->session()->regenerateToken();

	return redirect('/login');
})->name('logout')->middleware('auth');
