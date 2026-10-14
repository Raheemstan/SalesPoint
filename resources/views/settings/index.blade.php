@extends('layouts.app')

@section('title', 'Settings')

@php
    $admin = auth()->user()->hasRole('admin');

    /*
     * One source of truth for the tabs. `form` says whether the tab's fields
     * belong to the big settings form, which is what decides where the Save
     * button appears - the Users tab has nothing to save.
     */
    $tabs = [
        'store' => ['label' => 'Store', 'form' => true],
        'business' => ['label' => 'Business', 'form' => true],
        'currency' => ['label' => 'Currency', 'form' => true],
        'sales' => ['label' => 'Sales', 'form' => true],
        'receipt' => ['label' => 'Receipt', 'form' => true],
        'security' => ['label' => 'Security', 'form' => true],
    ];

    if ($admin) {
        $tabs['users'] = ['label' => 'Users', 'form' => false];
        $tabs['backup'] = ['label' => 'Backup', 'form' => true];
    }

    $formTabs = array_keys(array_filter($tabs, fn ($t) => $t['form']));

    /*
     * The tab strip reads as the underline nav the rest of the app uses. The
     * label has to sit immediately after the `>` of the opening tag: the
     * regression test for this screen looks for ">Store</button>", and a
     * newline between the two would not match.
     */
    $tabButton = 'inline-flex min-h-touch items-center whitespace-nowrap border-b-2 px-3 text-sm transition';
    $tabOn = 'border-primary-600 font-semibold text-primary-700 dark:border-primary-400 dark:text-primary-300';
    $tabOff = 'border-transparent font-medium text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-gray-100';

    $saveButton = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
@endphp

@section('content')
<div x-data="{ tab: '{{ $tabs === [] ? '' : array_key_first($tabs) }}' }">

    {{--
        One form across every settings tab, and it starts above the heading so
        the page's primary action - Save - can be a plain submit button in the
        header where every other screen keeps its main action.

        Hidden tabs are hidden with CSS, not removed, so their fields still
        post, which is what lets a single Save write the whole screen.
        Checkboxes are the exception by nature: an unticked box sends nothing,
        so the controller writes every flag on every save rather than only the
        ones it was sent.
    --}}
    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
        @csrf

        <x-page-header title="Settings"
                       subtitle="How this shop works: its details, its words, its money and who may do what.">
            <x-slot:actions>
                <button type="submit" x-show="{{ Js::from($formTabs) }}.includes(tab)" x-cloak
                        class="{{ $saveButton }}">
                    <x-icon name="check-circle" size="4" />
                    Save settings
                </button>
            </x-slot:actions>
        </x-page-header>

        <nav role="tablist" aria-label="Settings sections"
             class="scroll-thin mb-4 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700">
            @foreach ($tabs as $key => $tab)
                <button type="button" role="tab" id="settings-tab-{{ $key }}"
                    @click="tab = '{{ $key }}'"
                    :aria-selected="tab === '{{ $key }}' ? 'true' : 'false'"
                    :class="tab === '{{ $key }}' ? '{{ $tabOn }}' : '{{ $tabOff }}'"
                    class="{{ $tabButton }}">{{ $tab['label'] }}</button>
            @endforeach
        </nav>

        <div x-show="tab === 'store'" x-cloak role="tabpanel" aria-labelledby="settings-tab-store"
             class="space-y-4">
            @include('settings._store')
        </div>

        <div x-show="tab === 'business'" x-cloak role="tabpanel" aria-labelledby="settings-tab-business"
             class="space-y-4">
            @include('settings._business')
        </div>

        <div x-show="tab === 'currency'" x-cloak role="tabpanel" aria-labelledby="settings-tab-currency"
             class="space-y-4">
            @include('settings._currency')
        </div>

        <div x-show="tab === 'sales'" x-cloak role="tabpanel" aria-labelledby="settings-tab-sales"
             class="space-y-4">
            @include('settings._sales')
        </div>

        <div x-show="tab === 'receipt'" x-cloak role="tabpanel" aria-labelledby="settings-tab-receipt"
             class="space-y-4">
            @include('settings._receipt')
        </div>

        <div x-show="tab === 'security'" x-cloak role="tabpanel" aria-labelledby="settings-tab-security"
             class="space-y-4">
            @include('settings._security')
        </div>

        @if ($admin)
            <div x-show="tab === 'backup'" x-cloak role="tabpanel" aria-labelledby="settings-tab-backup">
                @include('settings._backup_schedule')
            </div>
        @endif

        {{-- The same action again at the foot of a long panel, so a tablet does
             not have to be scrolled back to the top to save. --}}
        <div x-show="{{ Js::from($formTabs) }}.includes(tab)" x-cloak class="mt-4 flex justify-end">
            <button type="submit" class="{{ $saveButton }}">
                <x-icon name="check-circle" size="4" />
                Save settings
            </button>
        </div>
    </form>

    {{--
        These panels contain forms of their own (delete a user, run a backup),
        and HTML forbids nesting forms, so they are siblings of the settings
        form rather than children of it. Alpine shows them on the same tab.
    --}}
    @if ($admin)
        <div x-show="tab === 'users'" x-cloak role="tabpanel" aria-labelledby="settings-tab-users"
             class="space-y-4">
            @include('settings.users-list')
        </div>

        <div x-show="tab === 'backup'" x-cloak class="mt-4">
            @include('settings.backup')
        </div>
    @endif
</div>

@if ($admin)
    @include('settings._user_modal')
@endif
@endsection
