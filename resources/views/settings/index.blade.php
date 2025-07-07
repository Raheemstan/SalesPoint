@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded p-4 text-sm">
    <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Settings</h2>

    {{-- Tabs --}}
    <ul id="settings-tabs" class="flex border-b text-sm font-medium text-gray-600 dark:text-gray-300 space-x-6 mb-6">
        @foreach(['general', 'receipt', 'theme', 'users', 'inventory', 'security', 'backup'] as $tab)
            <li class="tab-item cursor-pointer pb-2 border-b-2 transition" data-tab="{{ $tab }}">{{ ucfirst($tab) }}</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
        @csrf

        {{-- General --}}
        <div class="tab-content" id="tab-general">
            <x-section title="Business Info">
                <div class="grid md:grid-cols-2 gap-4">
                    <x-input name="store_name" label="Store Name" :value="setting('store_name')" />
                    <x-input name="store_phone" label="Phone" :value="setting('store_phone')" />
                    <x-input name="store_motto" label="Store Motto" :value="setting('store_motto')" />
                    <x-input name="email" label="Email" :value="setting('store_email')" />
                    <x-input name="store_address" label="Address" :value="setting('store_address')" />
                    <div>
                        <label class="block text-sm font-medium mb-1">Logo</label>
                        <input type="file" name="store_logo" id="logo-input"
                            class="form-input w-full text-sm text-gray-800 dark:text-gray-200" />
                        <div class="mt-2">
                            <img id="logo-preview" src="{{ setting('store_logo') ? asset('storage/' . setting('store_logo')) : '' }}"
                                class="h-16 w-auto object-contain">
                        </div>
                    </div>
                </div>
            </x-section>
        </div>

        {{-- Receipt --}}
        <div class="tab-content hidden" id="tab-receipt">
            <x-section title="Receipt Preferences">
                <x-select name="print_method" label="Print Method" :options="['dom' => 'DOM', 'escpos' => 'ESC/POS']" :value="setting('print_method')" />
                <x-checkbox name="show_logo" label="Show Logo" :checked="setting('show_logo')" />
                <x-checkbox name="show_business_info" label="Show Business Info" :checked="setting('show_business_info')" />
            </x-section>
        </div>

        {{-- Theme --}}
        <div class="tab-content hidden" id="tab-theme">
            <x-section title="Theme">
                <x-select name="theme" label="Preferred Theme"
                    :options="['light' => 'Light', 'dark' => 'Dark', 'system' => 'System']"
                    :value="setting('theme')" />
            </x-section>
        </div>

        {{-- Inventory --}}
        <div class="tab-content hidden" id="tab-inventory">
            <x-section title="Inventory Settings">
                <x-checkbox name="enable_low_stock_warning" label="Enable Low Stock Warnings"
                    :checked="setting('enable_low_stock_warning')" />
                <x-input name="low_stock_threshold" label="Threshold" type="number" :value="setting('low_stock_threshold')" />
            </x-section>
        </div>

        {{-- Security --}}
        <div class="tab-content hidden" id="tab-security">
            <x-section title="Security">
                <x-checkbox name="enable_audit_log" label="Enable Audit Logs" :checked="setting('enable_audit_log')" />
                <x-input name="auto_logout_time" label="Auto Logout (minutes)" type="number" :value="setting('auto_logout_time')" />
            </x-section>
        </div>

        {{-- Backup --}}
        <div class="tab-content hidden" id="tab-backup">
            <x-section title="Backup and Export">
                @include('settings.backup')
            </x-section>
        </div>

        {{-- Users --}}
        <div class="tab-content hidden" id="tab-users">
            <x-section title="User Management">
                <button type="button" id="open-user-modal" class="bg-blue-600 text-white px-4 py-2 rounded">
                    + Add User
                </button>
                @include('settings.users-list') {{-- Table with users and edit buttons --}}
            </x-section>
        </div>

        <div class="mt-6">
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded">
                Save All Settings
            </button>
        </div>
    </form>
</div>

{{-- User Modal --}}
@include('settings._user_modal')

@endsection

@section('scripts')
<script>
    const tabs = document.querySelectorAll('.tab-item');
    const contents = document.querySelectorAll('.tab-content');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('border-blue-500', 'font-semibold'));
            tab.classList.add('border-blue-500', 'font-semibold');
            contents.forEach(c => c.classList.add('hidden'));
            document.getElementById(`tab-${tab.dataset.tab}`).classList.remove('hidden');
        });
    });
    tabs[0].click(); // default tab

    // Logo preview
    document.getElementById('logo-input').addEventListener('change', function (e) {
        const file = e.target.files[0];
        const preview = document.getElementById('logo-preview');
        if (file) {
            const reader = new FileReader();
            reader.onload = e => preview.src = e.target.result;
            reader.readAsDataURL(file);
        }
    });

    // User Modal
    const userModal = document.getElementById('user-modal');
    document.getElementById('open-user-modal').addEventListener('click', () => {
        document.getElementById('user-form').reset();
        document.getElementById('user-modal-title').innerText = 'Add User';
        userModal.classList.remove('hidden');
    });
    document.getElementById('close-user-modal').addEventListener('click', () => {
        userModal.classList.add('hidden');
    });
</script>
@endsection
