<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login - OSCA System</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="mx-auto flex min-h-screen max-w-5xl items-center px-6 py-12">
        <div class="w-full overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 md:grid md:grid-cols-2">
            <div class="bg-slate-900 p-10 text-white">
                <h1 class="text-3xl font-bold">OSCA System</h1>
                <p class="mt-4 text-slate-200">Senior Citizen Profiling and QoL Analytics</p>
                <div class="mt-8 rounded-lg bg-slate-800 p-4 text-sm text-slate-200">
                    <p class="font-semibold">Default local account</p>
                    <p>Email: admin@osca.local</p>
                    <p>Password: password</p>
                </div>
            </div>

            <div class="p-10">
                <h2 class="text-2xl font-semibold">Sign in</h2>

                @if ($errors->any())
                    <div class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                            class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none ring-slate-300 focus:border-slate-400 focus:ring" />
                    </div>

                    <div>
                        <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                        <input id="password" name="password" type="password" required
                            class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none ring-slate-300 focus:border-slate-400 focus:ring" />
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="remember" class="rounded border-slate-300" />
                        Remember me
                    </label>

                    <button type="submit"
                        class="w-full rounded-md bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-800">
                        Login
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
