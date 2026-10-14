{{--
|--------------------------------------------------------------------------
| reports.partials.not-relevant
|--------------------------------------------------------------------------
|
|   @include('reports.partials.not-relevant', [
|       'message' => 'A salon keeps no stock, so there is nothing to value.',
|   ])
|
| Shown instead of a 404 when a report has nothing to say for the shop's
| business type. The link may well still be in somebody's bookmarks from when
| the feature was on, and the setting can be switched back - so the page
| explains itself and points at where to change it, rather than pretending not
| to exist.
--}}

<x-card>
    <x-empty icon="info" :title="$title ?? 'Not relevant to this business type'" :message="$message">
        <x-slot:action>
            <a href="{{ route('settings.index') }}"
               class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                Settings
            </a>
        </x-slot:action>
    </x-empty>
</x-card>
