<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Pilot ended</title>
    {{-- No external stylesheet, font or script: this page must render even if every
         other part of the app (and its asset build) is what's being gated. --}}
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a; color: #e2e8f0; margin: 0;
            display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 24px;
        }
        .card {
            max-width: 440px; width: 100%; background: #1e293b; border-radius: 16px;
            padding: 32px; box-shadow: 0 10px 30px rgba(0,0,0,.3);
        }
        h1 { font-size: 22px; margin: 0 0 12px; color: #f8fafc; }
        p { line-height: 1.6; color: #cbd5e1; margin: 0 0 12px; }
        .contact { margin-top: 20px; padding-top: 20px; border-top: 1px solid #334155; }
        .contact a { color: #7dd3fc; text-decoration: none; font-weight: 600; }
        .badge {
            display: inline-block; font-size: 12px; font-weight: 700; letter-spacing: .05em;
            text-transform: uppercase; color: #fca5a5; background: rgba(239,68,68,.15);
            padding: 4px 10px; border-radius: 999px; margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">Pilot ended</span>
        <h1>This pilot installation is no longer active.</h1>
        <p>Your data has not been changed, removed or exported. It is exactly where it was - this screen simply pauses access until the pilot is continued.</p>
        <p>To keep using the system, please get in touch so we can set it up on a full subscription.</p>
        <div class="contact">
            <p style="margin-bottom:4px;">
                <strong>Phone:</strong> <a href="tel:{{ $phone }}">{{ $phone }}</a>
            </p>
            @if ($email)
                <p style="margin-bottom:0;">
                    <strong>Email:</strong> <a href="mailto:{{ $email }}">{{ $email }}</a>
                </p>
            @endif
        </div>
    </div>
</body>
</html>
