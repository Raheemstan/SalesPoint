@php
    /*
    |--------------------------------------------------------------------------
    | Offline state, held sales, and refusals
    |--------------------------------------------------------------------------
    |
    | Three things on one strip above the till:
    |
    |   - whether this till has a line at all;
    |   - how many sales are sitting on this device and are not yet money;
    |   - any offline sale the server refused, which needs a person.
    |
    | A REFUSAL IS NOT A BADGE. The red banner below is rendered by Blade from
    | the server's own record, so it is on the screen before any JavaScript runs,
    | it survives this device being wiped, and it appears on every other till as
    | well. The panel also opens itself when a refusal arrives. There is
    | deliberately no way to dismiss one: it goes when the sale is banked.
    |
    | Every amount here is "what the till thought", labelled as such, and
    | formatted with money() so no currency symbol is written down anywhere.
    */

    // One sentence, built in one expression, so the rendered text has no stray
    // whitespace in the middle of it.
    $noun = strtolower($offlineRejected === 1 ? term('sale') : term('sale', true));
    $refusedHeadline = $offlineRejected === 1
        ? "1 offline {$noun} was refused and needs attention"
        : "{$offlineRejected} offline {$noun} were refused and need attention";

    /*
    | JSON for a <script type="application/json"> block. HEX_TAG is the one that
    | matters: it turns `<` and `>` into escapes, so a product called "</script>"
    | cannot break out of the block. Slashes are left alone only because a URL
    | full of `\/` is miserable to read when something is wrong.
    */
    $asJson = fn ($value) => json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
@endphp

{{--
    The server's description of this till: its URLs, its limits, the shop's
    currency and the sector's own nouns. Read by resources/js/offline/config.js,
    and its absence is how every other page in the app knows to do nothing.
--}}
<script type="application/json" id="offline-config">{!! $asJson($offlineConfig) !!}</script>

{{--
    No x-cloak on this element, deliberately. The refusal notice below is
    rendered by Blade, and `[x-cloak] { display: none }` would hide it for ever
    on a till whose JavaScript failed to load - which is exactly the till most
    likely to have offline sales stuck on it. Only the parts that would
    genuinely flash are cloaked.
--}}
<div id="offline-queue" class="mb-4" x-data="offlineQueue()">

    {{--
        Refused sales still sitting on THIS till. A different statement from the
        server-side notice below it: that one is the shop's record, this one is
        "you are holding money that was turned away, and here is the button".
    --}}
    <template x-if="refused.length > 0">
        <div class="mb-2 rounded-xl bg-red-50 p-3 ring-1 ring-red-300 dark:bg-red-950 dark:ring-red-800">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-sm font-bold text-red-800 dark:text-red-200">
                    <x-icon name="alert-triangle" size="5" />
                    <span x-text="'This till is holding ' + refused.length + ' refused ' +
                        (refused.length === 1 ? config.saleTerm.toLowerCase() : config.salesTerm.toLowerCase()) +
                        ' that ' + (refused.length === 1 ? 'was' : 'were') + ' not banked'"></span>
                </p>
                <button type="button" @click="open = true"
                        class="min-h-touch rounded-lg bg-red-600 px-3 text-sm font-semibold text-white hover:bg-red-700">
                    Show me
                </button>
            </div>
        </div>
    </template>

    @if ($offlineRejected > 0)
        {{--
            The same message without JavaScript, from the database. The count is
            the server's, so it is right even if this device has never held a
            sale - a supervisor on another till sees it too.
        --}}
        <div class="mb-2 rounded-xl bg-red-50 p-3 ring-1 ring-red-300 dark:bg-red-950 dark:ring-red-800">
            <p class="flex items-center gap-2 text-sm font-bold text-red-800 dark:text-red-200">
                <x-icon name="alert-triangle" size="5" />
                {{ $refusedHeadline }}
            </p>

            <ul class="mt-2 space-y-1.5">
                @foreach ($offlineRefused as $refused)
                    <li class="rounded-lg bg-white p-2 text-xs dark:bg-gray-800">
                        <p class="font-semibold text-gray-900 dark:text-gray-100">
                            {{ $refused['reference'] ?? 'No reference' }}
                            <span class="font-normal text-gray-500 dark:text-gray-400">
                                · {{ $refused['lines'] }} {{ Str::plural('line', $refused['lines']) }}
                                · the till said {{ $refused['claimed_total_display'] }}
                            </span>
                        </p>
                        <p class="mt-0.5 text-red-700 dark:text-red-300">{{ $refused['reason'] }}</p>
                    </li>
                @endforeach
            </ul>

            <p class="mt-2 text-xs text-red-800 dark:text-red-300">
                Nothing was banked for these. Deal with the reason, then press
                <span class="font-semibold">Try again</span> on the till that is holding them, or ring them up by hand.
            </p>
        </div>
    @endif

    {{-- The everyday strip: connection, queue depth, sync now. --}}
    <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white p-2.5 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
         :class="!online && 'ring-amber-300 dark:ring-amber-700'">

        <span class="flex items-center gap-1.5 text-sm font-semibold"
              :class="online ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-300'">
            <span class="h-2.5 w-2.5 rounded-full" :class="online ? 'bg-green-500' : 'bg-amber-500'"
                  aria-hidden="true"></span>
            {{-- Placeholder text, so the strip never renders blank before Alpine boots. --}}
            <span x-text="online ? 'Online' : 'Offline'">Checking the connection…</span>
        </span>

        <template x-if="!online">
            <span class="text-xs text-amber-700 dark:text-amber-300">
                <span x-text="config.salesTerm"></span> will be held on this till and sent up when the line is back.
            </span>
        </template>

        <template x-if="queued > 0">
            <span class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200">
                <x-icon name="clock" size="4" />
                <span x-text="queued + ' held · ' + value + ' not yet banked'"></span>
            </span>
        </template>

        <div class="ml-auto flex items-center gap-1.5">
            <template x-if="queued > 0">
                <button type="button" @click="open = !open"
                        class="min-h-touch rounded-lg bg-gray-100 px-3 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                    <span x-text="open ? 'Hide held' : 'Show held'"></span>
                </button>
            </template>

            <button type="button" @click="sync()" :disabled="busy || !online"
                    class="flex min-h-touch items-center gap-1.5 rounded-lg bg-primary-600 px-3 text-sm font-semibold text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:bg-gray-400 dark:disabled:bg-gray-600">
                <x-icon name="refresh" size="4" />
                <span x-text="busy ? 'Syncing…' : 'Sync now'"></span>
            </button>
        </div>
    </div>

    {{-- The held list. --}}
    <div x-show="open" x-cloak x-transition.opacity
         class="mt-2 rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">

        <div class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Held on this till
            </h3>
            <button type="button" @click="open = false" aria-label="Close held list"
                    class="flex h-touch w-touch items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                <x-icon name="x" size="4" />
            </button>
        </div>

        {{--
            The last thing that happened, kept on screen. A toast says it too,
            but a toast is gone in four seconds and a cashier serving a queue
            will have missed it.
        --}}
        <template x-if="message">
            <p class="mt-2 rounded-lg p-2 text-xs font-medium"
               :class="{
                   'bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200': messageVariant === 'success',
                   'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200': messageVariant === 'warning',
                   'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200': messageVariant === 'error',
                   'bg-gray-100 text-gray-700 dark:bg-gray-900 dark:text-gray-200': messageVariant === 'info',
               }"
               x-text="message"></p>
        </template>

        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            These are not yet in the books. The figures are what this till worked out; the server
            recomputes them from the catalogue when it banks each one.
        </p>

        {{-- The shop's record has moved on since this page was drawn. --}}
        <template x-if="recordIsStale">
            <p class="mt-2 rounded-lg bg-red-50 p-2 text-xs font-semibold text-red-800 dark:bg-red-950 dark:text-red-200"
               x-text="'The shop\'s record now shows ' + serverRejectedCount +
                       ' refused offline ' + (serverRejectedCount === 1 ? config.saleTerm.toLowerCase() : config.salesTerm.toLowerCase()) +
                       '. Reload the till to see all of them with their reasons.'"></p>
        </template>

        {{-- Refused: kept at the top, and they cannot be dismissed. --}}
        <template x-for="record in refused" :key="record.uuid">
            <div class="mt-2 rounded-lg bg-red-50 p-2.5 ring-1 ring-red-200 dark:bg-red-950 dark:ring-red-800">
                <p class="text-sm font-bold text-red-800 dark:text-red-200" x-text="record.reference"></p>
                <p class="mt-0.5 text-xs text-red-700 dark:text-red-300" x-text="record.reason"></p>
                <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400"
                   x-text="'Rung up ' + when(record.rung_up_at) + ' · the till said ' + money(record.payload.claimed_total)"></p>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    <button type="button" @click="retryOne(record.uuid)" :disabled="busy"
                            class="min-h-touch rounded-lg bg-red-600 px-3 text-xs font-semibold text-white hover:bg-red-700 disabled:bg-gray-400">
                        Try again
                    </button>
                    <button type="button" @click="reprint(record.uuid)"
                            class="min-h-touch rounded-lg bg-white px-3 text-xs font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600">
                        Print the slip again
                    </button>
                </div>
            </div>
        </template>

        {{-- Waiting to go up. --}}
        <template x-for="record in held" :key="record.uuid">
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-amber-50 p-2.5 ring-1 ring-amber-200 dark:bg-amber-950 dark:ring-amber-800">
                <div>
                    <p class="text-sm font-bold text-amber-900 dark:text-amber-100" x-text="record.reference"></p>
                    <p class="text-xs text-amber-800 dark:text-amber-200"
                       x-text="when(record.rung_up_at) + ' · ' + record.payload.lines.length + ' line' +
                               (record.payload.lines.length === 1 ? '' : 's') + ' · ' + money(record.payload.claimed_total) +
                               (record.attempts ? ' · ' + record.attempts + ' attempt' + (record.attempts === 1 ? '' : 's') : '')"></p>
                </div>
                <button type="button" @click="reprint(record.uuid)"
                        class="min-h-touch rounded-lg bg-white px-3 text-xs font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600">
                    Print the slip again
                </button>
            </div>
        </template>

        {{--
            How a customer's provisional slip is tied to the real invoice. Shown
            after a sync so the cashier can write the invoice number on a slip, or
            find the sale when somebody comes back with one.
        --}}
        <template x-if="reconciled.length">
            <div class="mt-3 border-t border-gray-200 pt-2 dark:border-gray-700">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Banked just now
                </h4>
                <template x-for="banked in reconciled" :key="banked.reference">
                    <div class="mt-1 text-xs">
                        <p class="text-gray-800 dark:text-gray-100">
                            <span class="font-mono font-semibold" x-text="banked.reference"></span>
                            is now
                            <span class="font-mono font-semibold" x-text="banked.invoice_number"></span>
                            <span x-show="banked.status === 'duplicate'" class="text-gray-500 dark:text-gray-400">
                                (it had already reached the server)
                            </span>
                        </p>
                        <template x-for="note in banked.notes" :key="note">
                            <p class="text-amber-700 dark:text-amber-300" x-text="note"></p>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>
