<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        {{-- An installed package's page ("package::page") lives in that package. --}}
        @php
            [$pagePackage, $pageName] = str_contains($page['component'], '::') ? explode('::', $page['component'], 2) : [null, $page['component']];
        @endphp
        @vite(['resources/css/app.css', 'resources/js/app.ts', $pagePackage ? "packages/vasuttam/{$pagePackage}/resources/js/pages/{$pageName}.vue" : "resources/js/pages/{$pageName}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
