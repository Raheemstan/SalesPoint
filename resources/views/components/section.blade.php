<div class="mb-6">
    @if($title)
        <h3 class="text-lg font-semibold mb-2 text-gray-800 dark:text-gray-200">{{ $title }}</h3>
    @endif
    <div>
        {{ $slot }}
    </div>
</div>