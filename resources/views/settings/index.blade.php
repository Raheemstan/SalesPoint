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

    $tabButton = 'cursor-pointer pb-2 px-1 border-b-2 transition whitespace-nowrap';
    $tabOn = 'border-blue-500 text-blue-600 dark:text-blue-400 font-semibold';
    $tabOff = 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100';
@endphp

@section('content')
{{-- Alpine sets x-show after it boots; without this every tab is visible for that instant. --}}
<style>[x-cloak] { display: none !important; }</style>

<div x-data="{ tab: '{{ $tabs === [] ? '' : array_key_first($tabs) }}' }" class="space-y-4 text-sm">

    <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
        <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Settings</h2>

        <nav class="flex gap-6 border-b dark:border-gray-700 overflow-x-auto font-medium">
            @foreach ($tabs as $key => $tab)
                <button type="button" @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? '{{ $tabOn }}' : '{{ $tabOff }}'"
                    class="{{ $tabButton }}">{{ $tab['label'] }}</button>
            @endforeach
        </nav>
    </div>

    {{--
        One form across every settings tab. Hidden tabs are hidden with CSS, not
        removed, so their fields still post - which is what lets a single Save
        button write the whole screen. Checkboxes are the exception by nature:
        an unticked box sends nothing, so the controller writes every flag on
        every save rather than only the ones it was sent.
    --}}
    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
        @csrf

        <div x-show="tab === 'store'" x-cloak class="bg-white dark:bg-gray-800 rounded shadow p-4">
            @include('settings._store')
        </div>

        <div x-show="tab === 'business'" x-cloak class="bg-white dark:bg-gray-800 rounded shadow p-4">
            @include('settings._business')
        </div>

        <div x-show="tab === 'currency'" x-cloak class="bg-white dark:bg-gray-800 rounded shadow p-4">
            @include('settings._currency')
        </div>

        <div x-show="tab === 'sales'" x-cloak class="bg-white dark:bg-gray-800 rounded shadow p-4">
            @include('settings._sales')
        </div>

        <div x-show="tab === 'receipt'" x-cloak class="bg-white dark:bg-gray-800 rounded shadow p-4">
            @include('settings._receipt')
        </div>

        <div x-show="tab === 'security'" x-cloak class="bg-white dark:bg-gray-800 rounded shadow p-4">
            @include('settings._security')
        </div>

        @if ($admin)
            <div x-show="tab === 'backup'" x-cloak class="bg-white dark:bg-gray-800 rounded shadow p-4">
                @include('settings._backup_schedule')
            </div>
        @endif

        <div x-show="{{ Js::from($formTabs) }}.includes(tab)" x-cloak class="mt-4">
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded">
                Save All Settings
            </button>
        </div>
    </form>

    {{--
        These panels contain forms of their own (delete a user, run a backup),
        and HTML forbids nesting forms, so they are siblings of the settings
        form rather than children of it. Alpine shows them on the same tab.
    --}}
    @if ($admin)
        <div x-show="tab === 'users'" x-cloak class="bg-white dark:bg-gray-800 rounded shadow p-4">
            @include('settings.users-list')
        </div>

        <div x-show="tab === 'backup'" x-cloak>
            @include('settings.backup')
        </div>
    @endif
</div>

@if ($admin)
    @include('settings._user_modal')
@endif
@endsection
