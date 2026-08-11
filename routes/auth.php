<?php

use App\Models\User;
use App\Support\SingleSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::middleware('guest')->group(function () {
    Route::get('/login', function () {
        return view('auth.login');
    })->name('login');

    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Verify credentials WITHOUT establishing a session yet, so we never
        // reveal "active elsewhere" to someone who lacks the correct password.
        if (! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();
        $sessionId = $request->session()->getId();

        // One active session per account: block if this account is already
        // signed in (and not idle) on another device.
        if (SingleSession::activeElsewhere($user, $sessionId)) {
            throw ValidationException::withMessages([
                'email' => 'Your account is currently active on another device. Please sign out there before starting a new session.',
            ]);
        }

        // Reclaim any idle/stale sessions for this account, then log in.
        SingleSession::releaseOthers($user, $sessionId);

        Auth::attempt($credentials, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    })->middleware('throttle:login');
});

Route::post('/logout', function (Request $request) {
    $reason = $request->input('reason');

    Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    // Flash AFTER invalidate() so it lands in the fresh session, not the
    // destroyed one. "inactivity" is sent by the idle-logout timer
    // (resources/js/idle-logout.js) via the hidden form in
    // components/idle-warning.blade.php; a normal profile-menu sign-out
    // sends no reason and stays silent.
    return $reason === 'inactivity'
        ? redirect('/login')->with('status', 'You were signed out due to inactivity.')
        : redirect('/login');
})->name('logout')->middleware('auth');
