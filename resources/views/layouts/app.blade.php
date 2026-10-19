<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts (same families as the reference portal) -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            body{font-family:"IBM Plex Sans","IBM Plex Sans Arabic",ui-sans-serif,system-ui,sans-serif}
            .font-mono,code,pre{font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,monospace}
            .app-topbar{position:sticky;top:0;z-index:10;height:56px;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 20px;background:#fff;border-bottom:1px solid oklch(91.2% .01 255.5)}
            .app-topbar-title{font-size:13px;font-weight:500;color:oklch(24% .028 258);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        </style>
        @include('partials.lovable-theme')
    </head>
    <body class="antialiased">
        <div class="min-h-screen bg-[#f4f8fc] flex" x-data="{ sidebarOpen: false }">
            @include('layouts.navigation')

            <div class="flex-1 min-w-0">
                <!-- Top bar -->
                <div class="app-topbar">
                    <div class="flex items-center gap-3 min-w-0">
                        <button @click="sidebarOpen = ! sidebarOpen" class="text-gray-500 sm:hidden shrink-0">
                            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <div class="app-topbar-title">{{ __('Business Data Management') }}</div>
                    </div>
                    <div class="flex items-center gap-4 shrink-0">
                        @include('layouts.notification-bell')
                    </div>
                </div>

                <!-- Page Content -->
                <main>
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
