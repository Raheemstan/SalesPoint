@php
    /*
    | Offsite backup: the copy that is not in the shop.
    |
    | These fields are part of the main settings form, like the schedule above
    | them, so one Save writes the lot. The two buttons are not: they are fetch
    | calls to their own endpoints, because a <form> cannot be nested inside the
    | settings form, and because "test this" should answer now without throwing
    | away the credentials somebody has just typed.
    |
    | Everything this needs it reads itself, so wiring it into the Backup tab is
    | one @include with no variables to pass.
    */
    $backups = app(\App\Services\BackupService::class);

    $disks = collect(config('filesystems.disks', []))->keys()
        ->mapWithKeys(fn ($name) => [$name => 'Disk: ' . $name])
        ->all();

    $keyIsSet = $backups->hasEncryptionKey();
    $pending = $backups->pendingOffsiteCount();
    $needsSchedule = $backups->needsSchedule();
    $offsiteOn = old('backup_offsite_enabled', setting('backup_offsite_enabled')) === '1';

    /*
    | Until the routes are registered the buttons are simply not drawn, rather
    | than throwing a RouteNotFoundException and taking the whole settings
    | screen down with them.
    */
    $testRoute = Route::has('settings.backups.offsite.test') ? route('settings.backups.offsite.test') : null;
    $uploadRoute = Route::has('settings.backups.offsite.upload') ? route('settings.backups.offsite.upload') : null;

    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';
    $check = 'mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 text-primary-600'
        . ' focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700';
    $secondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium'
        . ' text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'
        . ' dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';
@endphp

<div x-data="{
        enabled: {{ $offsiteOn ? 'true' : 'false' }},
        busy: false,
        result: null,
        resultOk: false,

        /* Both buttons answer in place: a shopkeeper testing credentials needs
           the reason, not a page reload that loses the form. */
        async call(url, body) {
            if (! url || this.busy) return;

            this.busy = true;
            this.result = null;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    body: JSON.stringify(body || {}),
                });

                const data = await response.json().catch(() => ({}));

                if (! response.ok) {
                    /* A validation error comes back as 422 with the field
                       messages, which are more use than 'request failed'. */
                    const first = data.errors ? Object.values(data.errors)[0][0] : null;
                    throw new Error(first || data.message || 'The server refused that (' + response.status + ').');
                }

                this.resultOk = data.ok === true;
                this.result = [data.message, data.hint].filter(Boolean).join(' ');
                window.toast(data.message, { variant: this.resultOk ? 'success' : 'error' });
            } catch (e) {
                this.resultOk = false;
                this.result = e.message;
                window.toast(e.message, { variant: 'error' });
            } finally {
                this.busy = false;
            }
        },

        test() {
            this.call(@js($testRoute), {
                backup_offsite_disk: this.$refs.disk?.value,
                backup_offsite_path: this.$refs.path?.value,
            });
        },

        uploadNow() {
            this.call(@js($uploadRoute), {});
        },

        /* The 'automatic backups are off' nudge. It ticks the box in the
           Automatic backups card and leaves Save to the person: a nightly job
           appearing on a shop's server without them pressing anything is not
           ours to decide. */
        turnScheduleOn() {
            const box = document.getElementById('backup_enabled');

            if (! box) {
                window.toast('Tick \'Back up automatically\' in the Automatic backups card, then save.', { variant: 'info' });
                return;
            }

            box.checked = true;
            box.dispatchEvent(new Event('change'));
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.toast('Automatic backups ticked - now press Save settings.', { variant: 'info' });
        },
    }">

    <x-card title="Offsite backup" subtitle="A copy somewhere the shop's own machine failing would not take with it.">
        <div class="flex items-start gap-3 pb-4">
            <input type="checkbox" id="backup_offsite_enabled" name="backup_offsite_enabled" value="1"
                   x-model="enabled"
                   @checked($offsiteOn) class="{{ $check }}">
            <div class="min-w-0">
                <label for="backup_offsite_enabled" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                    Send a copy of every backup offsite
                </label>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    After each backup the file is compressed, encrypted and uploaded to online storage. A
                    backup kept only on this machine is lost with the machine - a theft, a dead disk, a
                    fire or ransomware takes the database and the backup together.
                </p>
            </div>
        </div>

        <div class="space-y-4 border-t border-gray-200 pt-4 dark:border-gray-700" x-show="enabled" x-cloak>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="backup_offsite_disk" class="{{ $label }}">Storage</label>
                    <select id="backup_offsite_disk" name="backup_offsite_disk" x-ref="disk" class="{{ $control }}">
                        @foreach ($disks as $value => $text)
                            <option value="{{ $value }}"
                                @selected(old('backup_offsite_disk', setting('backup_offsite_disk', 's3')) === $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                    <p class="{{ $hint }}">
                        The <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-700">s3</code> disk works with
                        Amazon S3, Backblaze B2, Cloudflare R2, Wasabi, DigitalOcean Spaces and a MinIO box of
                        your own - the account details go in the server's .env file, not here.
                    </p>
                    @error('backup_offsite_disk')
                        <p class="{{ $error }}">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="backup_offsite_path" class="{{ $label }}">Folder in the bucket</label>
                    <input type="text" id="backup_offsite_path" name="backup_offsite_path" x-ref="path"
                           value="{{ old('backup_offsite_path', setting('backup_offsite_path', 'salespoint-backups')) }}"
                           placeholder="salespoint-backups" class="{{ $control }}">
                    <p class="{{ $hint }}">Leave it as it is unless the bucket is shared with something else.</p>
                    @error('backup_offsite_path')
                        <p class="{{ $error }}">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($testRoute)
                    <button type="button" @click="test()" :disabled="busy" class="{{ $secondary }}">
                        <x-icon name="refresh" size="4" />
                        <span x-text="busy ? 'Testing...' : 'Test the connection'"></span>
                    </button>
                @endif

                @if ($uploadRoute && $pending > 0)
                    <button type="button" @click="uploadNow()" :disabled="busy" class="{{ $secondary }}">
                        <x-icon name="upload" size="4" />
                        Upload the {{ $pending }} waiting {{ \Illuminate\Support\Str::plural('backup', $pending) }} now
                    </button>
                @endif
            </div>

            <div x-show="result" x-cloak class="rounded-lg p-3 text-xs ring-1"
                 :class="resultOk
                    ? 'bg-emerald-50 text-emerald-900 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-100 dark:ring-emerald-800'
                    : 'bg-red-50 text-red-900 ring-red-200 dark:bg-red-900/30 dark:text-red-100 dark:ring-red-800'">
                <p class="min-w-0" x-text="result"></p>
            </div>

            @if ($pending > 0)
                <div class="flex items-start gap-3 rounded-lg bg-blue-50 p-3 text-xs text-blue-900
                            ring-1 ring-blue-200 dark:bg-blue-900/30 dark:text-blue-100 dark:ring-blue-800">
                    <span class="mt-0.5 shrink-0 text-blue-600 dark:text-blue-300">
                        <x-icon name="clock" size="5" />
                    </span>
                    <p class="min-w-0">
                        <strong>{{ $pending }} {{ \Illuminate\Support\Str::plural('backup', $pending) }} still
                        {{ $pending === 1 ? 'has' : 'have' }} no offsite copy.</strong>
                        They are retried every hour on their own, and none of them will be deleted by retention
                        while that copy is still owed - for now they are the only copies that exist. The reason
                        for each is in the backup history.
                    </p>
                </div>
            @endif
        </div>

        <x-slot:footer>
            Currently set to write to {{ $backups->offsiteDestinationLabel() }}. Email is not used to carry
            backups: providers cap attachments at 10-25 MB and a growing shop crosses that without warning.
        </x-slot:footer>
    </x-card>

    @if ($needsSchedule)
        {{-- The "when online, set it up" nudge: wanting offsite copies and
             having nothing that takes them is the one combination that looks
             set up and is not. --}}
        <x-card title="Automatic backups are switched off" subtitle="Offsite backup is on, but nothing is taking a backup on its own.">
            <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900
                        ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:ring-amber-800">
                <span class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-300">
                    <x-icon name="alert-triangle" size="5" />
                </span>
                <p class="min-w-0">
                    Nothing will go offsite until somebody presses <strong>Run a backup now</strong>. Switching
                    on <strong>Back up automatically</strong> in the Automatic backups card is what makes this
                    happen by itself, every night.
                </p>
            </div>

            <div class="mt-3">
                <button type="button" @click="turnScheduleOn()" class="{{ $secondary }}">
                    <x-icon name="check-circle" size="4" />
                    Switch automatic backups on
                </button>
                <p class="{{ $hint }}">This ticks the box for you. Nothing changes until you press Save settings.</p>
            </div>
        </x-card>
    @endif

    <x-card title="Encryption" subtitle="What stops a stolen bucket from being a stolen customer list.">
        <div class="flex items-start gap-3 pb-4">
            <input type="checkbox" id="backup_encrypt" name="backup_encrypt" value="1"
                   @checked(old('backup_encrypt', setting('backup_encrypt', '1'))) class="{{ $check }}">
            <div class="min-w-0">
                <label for="backup_encrypt" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                    Encrypt backups before they leave this machine
                </label>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    A backup holds every customer's name, phone number and address, every sale ever rung up,
                    and the staff's password and two-factor details. Leave this on.
                </p>
            </div>
        </div>

        <div class="border-t border-gray-200 pt-4 dark:border-gray-700">
            <label for="backup_encryption_key" class="{{ $label }}">
                Encryption key
                @if ($keyIsSet)
                    <span class="ml-1 align-middle text-xs font-normal text-emerald-700 dark:text-emerald-300">
                        &mdash; a key is saved
                    </span>
                @else
                    <span class="ml-1 align-middle text-xs font-normal text-amber-700 dark:text-amber-300">
                        &mdash; not set yet
                    </span>
                @endif
            </label>

            <input type="password" id="backup_encryption_key" name="backup_encryption_key"
                   autocomplete="new-password" spellcheck="false"
                   placeholder="{{ $keyIsSet ? 'Leave blank to keep the key you already have' : 'Leave blank and one will be generated' }}"
                   class="{{ $control }} font-mono">

            <p class="{{ $hint }}">
                Never shown again once saved, the same as a password. Leaving this blank keeps the key you
                already have; typing a new one replaces it, and backups taken before today will still need
                the old one.
            </p>
            @error('backup_encryption_key')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <div class="mt-4 flex items-start gap-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900
                    ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:ring-amber-800">
            <span class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-300">
                <x-icon name="key" size="5" />
            </span>
            <p class="min-w-0">
                <strong>Write the key down, somewhere that is not this machine.</strong>
                An encrypted backup cannot be opened without it by anybody, including us. To read it, run
                <code class="rounded bg-amber-100 px-1 py-0.5 dark:bg-amber-900/60">php artisan salespoint:backup:key --show</code>
                on the server, and keep the result with the shop's other papers - not in the same place as
                the backups.
            </p>
        </div>

        <x-slot:footer>
            To restore a backup:
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-700">php artisan salespoint:backup:restore</code>
            fetches the newest copy, checks it and decrypts it. It never overwrites the live database on its own.
        </x-slot:footer>
    </x-card>

    <x-card title="Backup reports by email" subtitle="So the morning a backup stops working is the morning you hear about it.">
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            <div class="flex items-start gap-3 pb-3">
                <input type="checkbox" id="backup_email_report" name="backup_email_report" value="1"
                       @checked(old('backup_email_report', setting('backup_email_report'))) class="{{ $check }}">
                <div class="min-w-0">
                    <label for="backup_email_report" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                        Email a short report after every backup
                    </label>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Whether it worked, how big it was, where it went, and how many copies are being kept.
                        Off by default.
                    </p>
                </div>
            </div>

            <div class="flex items-start gap-3 pt-3">
                <input type="checkbox" id="backup_email_attach" name="backup_email_attach" value="1"
                       @checked(old('backup_email_attach', setting('backup_email_attach'))) class="{{ $check }}">
                <div class="min-w-0">
                    <label for="backup_email_attach" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                        Attach the backup to the report while it is small enough
                    </label>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Only ever the encrypted copy, and only under the size limit below. Above it, the
                        report is sent on its own and says why. With encryption switched off, nothing is
                        attached at all - a plain dump is not something to put in an inbox.
                    </p>
                </div>
            </div>
        </div>

        <div class="mt-4 grid gap-4 border-t border-gray-200 pt-4 dark:border-gray-700 sm:grid-cols-2">
            <div>
                <label for="backup_email_to" class="{{ $label }}">Send reports to</label>
                <input type="text" id="backup_email_to" name="backup_email_to"
                       value="{{ old('backup_email_to', setting('backup_email_to')) }}"
                       placeholder="owner@example.com, bookkeeper@example.com" class="{{ $control }}">
                <p class="{{ $hint }}">
                    Separate several addresses with commas. Empty uses the shop's own email address from the
                    Store tab.
                </p>
                @error('backup_email_to')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="backup_email_max_mb" class="{{ $label }}">Never attach anything larger than (MB)</label>
                <input type="number" id="backup_email_max_mb" name="backup_email_max_mb" min="1" max="25"
                       value="{{ old('backup_email_max_mb', setting('backup_email_max_mb', 15)) }}"
                       class="{{ $control }} tabular-nums">
                <p class="{{ $hint }}">15 is a safe figure. Most providers refuse anything over 25.</p>
                @error('backup_email_max_mb')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </x-card>

    <x-card title="How long offsite copies are kept" subtitle="Storage costs money; losing last quarter's figures costs more.">
        <div class="sm:max-w-sm">
            <label for="backup_offsite_retention_days" class="{{ $label }}">Delete offsite backups older than (days)</label>
            <input type="number" id="backup_offsite_retention_days" name="backup_offsite_retention_days"
                   min="0" max="3650"
                   value="{{ old('backup_offsite_retention_days', setting('backup_offsite_retention_days', 90)) }}"
                   class="{{ $control }} tabular-nums">
            <p class="{{ $hint }}">
                0 keeps them for ever. Usually longer than the local setting: the copy on this machine is for
                last night's mistake, the offsite one is for last quarter's figures.
            </p>
            @error('backup_offsite_retention_days')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            The most recent offsite copy is never deleted, whatever this says - and a backup is never removed
            from this machine while its offsite copy is still waiting to be made.
        </p>
    </x-card>
</div>
