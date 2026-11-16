{{-- A signed-in user who may not use the admin panel (AuthenticateAdmin). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Access denied') }} - NexusDAV</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; }
        main { max-width: 28rem; margin: 1.5rem; padding: 2rem; border-radius: 0.75rem; background: #fff; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); text-align: center; }
        h1 { margin: 0 0 0.75rem; font-size: 1.25rem; }
        p { margin: 0 0 1.5rem; color: #4b5563; }
        a, button { display: inline-block; padding: 0.5rem 1rem; border: 0; border-radius: 0.5rem; background: #2563eb; color: #fff; font: inherit; text-decoration: none; cursor: pointer; }
        @media (prefers-color-scheme: dark) {
            body { background: #030712; color: #f9fafb; }
            main { background: #111827; }
            p { color: #9ca3af; }
        }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('Access denied') }}</h1>
        <p>{{ __('The admin panel is for administrators.') }}</p>

        @if ($portalUrl)
            <a href="{{ $portalUrl }}">{{ __('User portal') }}</a>
        @else
            <form method="post" action="{{ $logoutUrl }}">
                @csrf
                <button type="submit">{{ __('Sign out') }}</button>
            </form>
        @endif
    </main>
</body>
</html>
