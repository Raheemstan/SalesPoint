@extends('layouts.app')

@section('title', 'Two-factor authentication')

@php
    // Shown once, straight after confirming or regenerating. Never rendered
    // from the database.
    $codes = session('two_factor.recovery_codes') ?? $recoveryCodes ?? null;
@endphp

@section('content')
<style>[x-cloak] { display: none !important; }</style>

<div class="max-w-2xl space-y-4 text-sm">

    <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
        <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">Two-factor authentication</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            A second step at sign-in: your password, then a six-digit code from an app on your phone.
            A stolen or guessed password is no longer enough on its own.
        </p>

        @if ($enabled)
            <p class="mt-3">
                <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800
                             dark:bg-green-900 dark:text-green-200">On</span>
                <span class="text-gray-500 dark:text-gray-400 text-xs ml-1">
                    Switched on {{ $user->two_factor_confirmed_at->format('d M Y') }}.
                </span>
            </p>
        @else
            <p class="mt-3">
                <span class="px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-700
                             dark:bg-gray-600 dark:text-gray-200">Off</span>
                @if ($required)
                    <span class="text-xs text-amber-600 dark:text-amber-400 ml-1">
                        This shop requires administrators to use it.
                    </span>
                @endif
            </p>
        @endif
    </div>

    {{-- The codes, the one time they are ever shown. --}}
    @if ($codes)
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 border-l-4 border-amber-500">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-1">Recovery codes</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                Each one works once, in place of a code from your phone. Print them or write them down
                now and keep them away from the till - <strong>this is the only time they are
                shown</strong>, and nobody, including an administrator, can read them back out.
            </p>
            <ul class="grid grid-cols-2 gap-2 font-mono">
                @foreach ($codes as $code)
                    <li class="rounded bg-gray-100 dark:bg-gray-700 px-3 py-1.5 text-gray-800 dark:text-gray-100">
                        {{ $code }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (!$enabled && !$pendingSecret)
        {{-- Step 0: nothing set up yet. --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-1">Set it up</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                You will need an authenticator app - Google Authenticator, Microsoft Authenticator,
                Authy, 1Password and most password managers all work. Nothing changes about your
                sign-in until you have typed a code back and it has been accepted.
            </p>
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    Start setup
                </button>
            </form>
        </div>
    @endif

    @if (!$enabled && $pendingSecret)
        {{-- Step 1 and 2: the secret, then the proof. --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-1">1. Add it to your app</h3>

            {{--
                No QR image. Drawing one needs a QR encoder, and this install
                bundles no JavaScript library that has one - adding a dependency
                was not an option here. Typing the key in by hand is a supported
                path in every authenticator app, so that is what is offered,
                along with the raw otpauth:// URI for anything that can take it.
            --}}
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                In your authenticator app choose <em>add account</em>, then <em>enter a setup key</em>
                (sometimes called "manual entry"), and type this key:
            </p>

            <div x-data="{ copied: false }">
                <p class="font-mono text-lg tracking-widest break-all rounded bg-gray-100 dark:bg-gray-700
                          px-3 py-2 text-gray-800 dark:text-gray-100">
                    {{ trim(chunk_split($pendingSecret, 4, ' ')) }}
                </p>
                <button type="button" class="mt-2 text-xs text-blue-600 dark:text-blue-400 hover:underline"
                    @click="navigator.clipboard.writeText('{{ $pendingSecret }}').then(() => copied = true)">
                    <span x-show="!copied">Copy the key</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>

            <details class="mt-3">
                <summary class="text-xs text-gray-500 dark:text-gray-400 cursor-pointer">
                    Setup link (for an app that can take a URI)
                </summary>
                <p class="mt-1 text-xs font-mono break-all text-gray-600 dark:text-gray-300">{{ $pendingUri }}</p>
            </details>

            <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
                Account: <strong>{{ $user->email }}</strong> at
                <strong>{{ setting('store_name') ?: config('app.name') }}</strong>.
                Use the type "time-based" if your app asks.
            </p>

            <hr class="my-4 border-gray-200 dark:border-gray-700">

            <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-1">2. Prove it worked</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                Enter the six-digit code your app is showing now. Two-factor is only switched on once
                this is accepted, so a mistyped key cannot lock you out.
            </p>

            <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex flex-wrap items-start gap-2">
                @csrf
                <div>
                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                        maxlength="6" placeholder="123456" required autofocus
                        class="w-32 px-3 py-2 border rounded-md shadow-sm font-mono tracking-widest
                               dark:bg-gray-700 dark:text-white dark:border-gray-600">
                    @error('code')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">
                    Finish setup
                </button>
            </form>
        </div>
    @endif

    @if ($enabled)
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-1">New recovery codes</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                Generate a fresh set if you have used one, or if the list might have been seen. The old
                codes stop working immediately.
            </p>
            <form method="POST" action="{{ route('two-factor.recovery-codes') }}">
                @csrf
                <button class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded">
                    Generate new codes
                </button>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-1">Turn it off</h3>

            @if ($required)
                <p class="text-xs text-amber-600 dark:text-amber-400">
                    This shop requires administrators to use two-factor authentication, so it cannot be
                    switched off while you are an admin. An admin can change that in
                    Settings &rsaquo; Security.
                </p>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                    Your password is needed, so an unlocked browser left on the counter is not enough to
                    remove your second factor.
                </p>
                <form method="POST" action="{{ route('two-factor.disable') }}" class="flex flex-wrap items-start gap-2">
                    @csrf
                    @method('DELETE')
                    <div>
                        <input type="password" name="password" placeholder="Your password" required
                            autocomplete="current-password"
                            class="px-3 py-2 border rounded-md shadow-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
                        @error('password')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <button class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                        Turn off two-factor
                    </button>
                </form>
            @endif
        </div>
    @endif
</div>
@endsection
