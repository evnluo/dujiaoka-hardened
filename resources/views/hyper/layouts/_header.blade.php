<head>
    <meta charset="utf-8" />
    <title>{{ isset($page_title) ? $page_title : '' }} | {{ dujiaoka_config_get('title') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="Keywords" content="{{ dujiaoka_config_get('keywords') }}">
    <meta name="Description" content="{{ dujiaoka_config_get('description') }}">
    @if(\request()->getScheme() == "https")
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    @endif
    <link rel="shortcut icon" href="/favicon.ico">
    <link href="/assets/hyper/css/vendor/jquery-jvectormap-1.2.2.css" rel="stylesheet" type="text/css">
    <link href="/assets/hyper/css/icons.min.css" rel="stylesheet" type="text/css">
    <link href="/assets/hyper/css/app-creative.min.css?v=111614" rel="stylesheet" type="text/css" id="light-style">
    <link href="/assets/hyper/css/hyper.css?v=111614" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="/assets/hyper/css/fonts.css?v=111614">
    <link rel="stylesheet" href="https://assets.ohevan.com/lib/toastify/toastify.min.css">
    <script src="https://assets.ohevan.com/projects/tailwind-playcdn/3.4-typography.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
