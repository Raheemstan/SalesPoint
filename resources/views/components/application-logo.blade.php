{{--
    The shop's own logo where one has been uploaded, falling back to the
    SalesPoint mark. Used on the guest/login screens.

    MediaService::url() returns null when the stored file is not actually on the
    disk, so a dangling setting (a restored database, a half-copied deploy)
    shows the fallback rather than a broken image.
--}}
@php
    $shopLogo = app(\App\Services\MediaService::class)->url(setting('store_logo'));
@endphp

<img src="{{ $shopLogo ?? asset('logo.png') }}"
     alt="{{ setting('store_name', 'SalesPoint') }}"
     width="250" height="250"
     onerror="this.onerror=null; this.src='{{ asset('logo.png') }}';">
