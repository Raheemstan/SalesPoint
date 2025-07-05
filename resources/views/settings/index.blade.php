@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <div class="bg-white dark:bg-gray-800 shadow rounded p-4">
        <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Settings</h2>

        {{-- Tab Navigation --}}
        <ul id="settings-tabs" class="flex border-b mb-6 text-sm font-medium text-gray-600 dark:text-gray-300 space-x-6">
            @foreach(['general', 'receipt', 'theme', 'users', 'inventory', 'security', 'backup'] as $tab)
                <li class="tab-item cursor-pointer pb-2 border-b-2 transition" data-tab="{{ $tab }}">
                    {{ ucfirst($tab) }}
                </li>
            @endforeach
        </ul>

        {{-- Settings Form --}}
        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
            @csrf

            {{-- General --}}
            <div class="tab-content" id="tab-general">
                <h3 class="text-lg font-bold mb-2">Business Info</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input name="store_name" label="Store Name" :value="setting('store_name')" />
                    <x-input name="store_phone" label="Phone Number" :value="setting('store_phone')" />
                    <x-input name="store_motto" label="Store Motto" :value="setting('store_motto')" />
                    <x-input name="email" label="Email" :value="setting('store_email')" />
                    <x-input name="store_address" label="Business Address" :value="setting('store_address')" />
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Logo</label>
                        <input type="file" name="store_logo" accept="image/*" id="logo-input"
                            class="form form-control mt-1 block w-full text-sm text-gray-800 dark:text-gray-200 ">

                        {{-- Preview --}}
                        <div class="mt-2">
                            <img id="logo-preview"
                                src="{{ setting('store_logo') ? asset('storage/' . setting('store_logo')) : '' }}"
                                class="h-24 w-auto max-w-[60px] object-contain" alt="">
                        </div>
                    </div>

                </div>
            </div>

            {{-- Receipt --}}
            <div class="tab-content hidden" id="tab-receipt">
                <h3 class="text-lg font-bold mb-2">Receipt Preferences</h3>
                <x-select name="print_method" label="Print Method" :options="['dom' => 'DOM Print', 'escpos' => 'ESC/POS']"
                    :value="setting('print_method')" />
                <x-checkbox name="show_logo" label="Show Logo on Receipt" :checked="setting('show_logo')" />
                <x-checkbox name="show_business_info" label="Show Business Info on Receipt"
                    :checked="setting('show_business_info')" />
            </div>

            {{-- Theme --}}
            <div class="tab-content hidden" id="tab-theme">
                <h3 class="text-lg font-bold mb-2">Theme Preference</h3>
                <x-select name="theme" label="Theme" :options="['light' => 'Light', 'dark' => 'Dark', 'system' => 'System']"
                    :value="setting('theme')" />
            </div>

            {{-- Inventory --}}
            <div class="tab-content hidden" id="tab-inventory">
                <h3 class="text-lg font-bold mb-2">Inventory Settings</h3>
                <x-checkbox name="enable_low_stock_warning" label="Enable Low Stock Warnings"
                    :checked="setting('enable_low_stock_warning')" />
                <x-input name="low_stock_threshold" label="Low Stock Threshold" type="number"
                    :value="setting('low_stock_threshold')" />
            </div>

            {{-- Security --}}
            <div class="tab-content hidden" id="tab-security">
                <h3 class="text-lg font-bold mb-2">Security Settings</h3>
                <x-checkbox name="enable_audit_log" label="Enable Audit Logging" :checked="setting('enable_audit_log')" />
                <x-input name="auto_logout_time" label="Auto Logout Time (minutes)" type="number"
                    :value="setting('auto_logout_time')" />
            </div>

            {{-- Backup --}}
            <div class="tab-content hidden" id="tab-backup">
                <h3 class="text-lg font-bold mb-2">Backup and Export</h3>
                @include('settings.backup')
            </div>

            <div class="mt-6">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded">
                    Save Settings
                </button>
            </div>{{-- Users --}}
            <div class="tab-content hidden" id="tab-users">
                <h3 class="text-lg font-bold mb-2">User Management</h3>
                @include('settings.users')
            </div>

        </form>

    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tabs = document.querySelectorAll('.tab-item');
            const contents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => {
                        t.classList.remove('border-blue-500', 'text-blue-700', 'font-semibold');
                        t.classList.add('border-transparent');
                    });

                    tab.classList.add('border-blue-500', 'text-blue-700', 'font-semibold');
                    tab.classList.remove('border-transparent');

                    contents.forEach(c => c.classList.add('hidden'));
                    document.getElementById(`tab-${tab.dataset.tab}`).classList.remove('hidden');
                });
            });

            // Activate first tab on load
            tabs[0].click();
        });
        document.getElementById('logo-input').addEventListener('change', function (e) {
            const file = e.target.files[0];
            const preview = document.getElementById('logo-preview');

            if (file) {
                const reader = new FileReader();
                reader.onload = function (event) {
                    preview.src = event.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    </script>

@endsection