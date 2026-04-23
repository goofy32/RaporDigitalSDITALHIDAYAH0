<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="turbo-cache-control" content="no-preview">
    <meta name="turbo-visit-control" content="reload">
    <meta name="active-tahun-ajaran" content="{{ isset($activeTahunAjaran) ? $activeTahunAjaran->tahun_ajaran : '' }}">
    @yield('role-meta')

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    <title>@yield('title')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    @stack('styles')
</head>
<body @yield('layout-body')>
    <x-admin.topbar data-turbo-permanent id="topbar"></x-admin.topbar>
    @yield('sidebar')
    <x-session-timeout-alert data-turbo-permanent id="session-alert" />

    @yield('layout-content')

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    @yield('role-scripts')
    @stack('scripts')
</body>
</html>
