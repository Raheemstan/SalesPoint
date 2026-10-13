{{--
    The challenge. A half-authenticated step: the password was right, but
    nobody is signed in until a code checks out, so this uses the guest layout
    and there is no navigation to escape into.
--}}
<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">One more step</h2>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        Enter the six-digit code from your authenticator app. Codes last 30 seconds, so use the one
        showing now.
    </p>

    <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-4">
        @csrf

        <div>
            <x-input-label for="code" :value="__('Authentication code')" />
            <x-text-input id="code" class="block mt-1 w-full font-mono tracking-widest" type="text" name="code"
                inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" autofocus />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        {{-- Folded away: it is the path for a lost phone, not the normal one. --}}
        <details class="mt-4">
            <summary class="text-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                {{ __('Lost your phone? Use a recovery code') }}
            </summary>
            <div class="mt-2">
                <x-input-label for="recovery_code" :value="__('Recovery code')" />
                <x-text-input id="recovery_code" class="block mt-1 w-full font-mono" type="text"
                    name="recovery_code" maxlength="32" placeholder="XXXXX-XXXXX" autocomplete="one-time-code" />
                <x-input-error :messages="$errors->get('recovery_code')" class="mt-2" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Each recovery code works once. Generate a new set after signing in.') }}
                </p>
            </div>
        </details>

        <div class="flex items-center justify-end mt-6 gap-4">
            <a href="{{ route('login') }}"
                class="text-sm text-gray-600 dark:text-gray-400 underline hover:text-gray-900 dark:hover:text-gray-100">
                {{ __('Start again') }}
            </a>
            <x-primary-button>{{ __('Verify') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
