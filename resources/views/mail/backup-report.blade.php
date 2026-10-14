{{--
    The nightly backup report. Plain HTML with inline styles on purpose: mail
    clients strip <style> blocks and know nothing about Tailwind, and this file
    is rendered somewhere we do not control.

    Written to be read on a phone, at a glance, by somebody who is not technical
    and does not want to think about backups. The top line is the answer; the
    detail is underneath for the day it matters.
--}}
@php
    $ok = $backup->succeeded();
    $offsiteOk = $backup->offsite_status === \App\Models\Backup::OFFSITE_UPLOADED;
    $offsiteFailed = $backup->offsite_status === \App\Models\Backup::OFFSITE_FAILED;

    $banner = $ok && (! $facts['offsiteEnabled'] || $offsiteOk) ? '#047857' : ($ok ? '#b45309' : '#b91c1c');

    $heading = ! $ok
        ? 'The backup failed'
        : ($facts['offsiteEnabled'] && ! $offsiteOk ? 'Backed up here, but not offsite' : 'Backed up');

    $row = 'padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:13px;';
    $label = 'color:#6b7280;';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Backup report</title>
</head>
<body style="margin:0; padding:24px; background:#f3f4f6; font-family:Arial, Helvetica, sans-serif; color:#111827;">

<div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:6px; overflow:hidden;">

    <div style="background:{{ $banner }}; color:#ffffff; padding:16px 24px;">
        <p style="margin:0; font-size:12px; opacity:0.85;">{{ $facts['shop'] }}</p>
        <h1 style="margin:2px 0 0; font-size:20px;">{{ $heading }}</h1>
        <p style="margin:4px 0 0; font-size:12px; opacity:0.9;">{{ $facts['when'] }}</p>
    </div>

    <div style="padding:24px;">

        @if (! $ok)
            <p style="margin:0 0 16px; padding:12px; background:#fef2f2; color:#991b1b; border-radius:4px; font-size:13px;">
                <strong>No copy of the database was taken.</strong><br>
                {{ $backup->error }}
            </p>
        @endif

        @if ($offsiteFailed)
            <p style="margin:0 0 16px; padding:12px; background:#fffbeb; color:#92400e; border-radius:4px; font-size:13px;">
                <strong>This needs somebody to look at it.</strong>
                A copy was saved on the shop's own machine, but it could not be sent offsite, and the
                reason is not one that will clear by itself:<br>
                {{ $backup->offsite_error }}
            </p>
        @elseif ($facts['offsiteEnabled'] && ! $offsiteOk && $ok)
            <p style="margin:0 0 16px; padding:12px; background:#eff6ff; color:#1e40af; border-radius:4px; font-size:13px;">
                <strong>Waiting to go offsite.</strong>
                The copy on the shop's machine is safe and the upload will be tried again automatically.
                The local copy will not be deleted while it is the only one.
            </p>
        @endif

        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td style="{{ $row }} {{ $label }}">File</td>
                <td style="{{ $row }} text-align:right; word-break:break-all;">{{ $backup->filename }}</td>
            </tr>
            <tr>
                <td style="{{ $row }} {{ $label }}">Size</td>
                <td style="{{ $row }} text-align:right;">{{ $backup->size_for_humans }}</td>
            </tr>
            <tr>
                <td style="{{ $row }} {{ $label }}">Kept on this machine</td>
                <td style="{{ $row }} text-align:right; word-break:break-all;">{{ $facts['localDestination'] }}</td>
            </tr>

            @if ($facts['offsiteEnabled'])
                <tr>
                    <td style="{{ $row }} {{ $label }}">Offsite</td>
                    <td style="{{ $row }} text-align:right; word-break:break-all;">
                        {{ $facts['offsiteDestination'] }}
                    </td>
                </tr>
                <tr>
                    <td style="{{ $row }} {{ $label }}">Offsite copy</td>
                    <td style="{{ $row }} text-align:right;">
                        {{ $backup->offsite_label }}
                        @if ($offsiteOk)
                            ({{ $backup->offsite_size_for_humans }} compressed{{ $backup->offsite_encrypted ? ' and encrypted' : '' }})
                        @endif
                    </td>
                </tr>
            @else
                <tr>
                    <td style="{{ $row }} {{ $label }}">Offsite</td>
                    <td style="{{ $row }} text-align:right;">Switched off</td>
                </tr>
            @endif

            <tr>
                <td style="{{ $row }} {{ $label }}">Copies kept here</td>
                <td style="{{ $row }} text-align:right;">
                    {{ $facts['localKept'] }}
                    @if ($facts['localRetention'] > 0)
                        (deleted after {{ $facts['localRetention'] }} days)
                    @elseif ($facts['localRetentionCount'] > 0)
                        (newest {{ $facts['localRetentionCount'] }} kept)
                    @else
                        (kept for ever)
                    @endif
                </td>
            </tr>

            @if ($facts['offsiteEnabled'])
                <tr>
                    <td style="{{ $row }} {{ $label }}">Copies kept offsite</td>
                    <td style="{{ $row }} text-align:right;">
                        {{ $facts['offsiteKept'] }}
                        @if ($facts['offsiteRetention'] > 0)
                            (deleted after {{ $facts['offsiteRetention'] }} days)
                        @else
                            (kept for ever)
                        @endif
                    </td>
                </tr>
            @endif

            @if ($facts['pending'] > 0)
                <tr>
                    <td style="{{ $row }} {{ $label }}">Still to upload</td>
                    <td style="{{ $row }} text-align:right;">{{ $facts['pending'] }}</td>
                </tr>
            @endif
        </table>

        @if ($facts['needsSchedule'])
            <p style="margin:16px 0 0; padding:12px; background:#fffbeb; color:#92400e; border-radius:4px; font-size:13px;">
                <strong>Automatic backups are switched off.</strong>
                Offsite backup is set up, but nothing is taking a backup on a schedule, so this only happens
                when somebody presses the button. Turn on <em>Back up automatically</em> in
                Settings &gt; Backup.
            </p>
        @endif

        @if ($attached)
            <p style="margin:16px 0 0; font-size:13px; color:#374151;">
                The backup is attached, compressed and encrypted. It cannot be opened without the shop's
                backup encryption key - keep both safe, and keep them in different places.
            </p>
        @elseif ($attachmentNote)
            <p style="margin:16px 0 0; font-size:13px; color:#6b7280;">{{ $attachmentNote }}</p>
        @endif

        <p style="margin:20px 0 0; font-size:12px; color:#9ca3af;">
            Sent by {{ $facts['shop'] }} because backup reports are switched on in Settings &gt; Backup.
        </p>
    </div>
</div>

</body>
</html>
