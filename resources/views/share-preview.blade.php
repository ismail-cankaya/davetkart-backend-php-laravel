{{-- FE-04: paylasim onizlemesi (yalnizca botlar). Her deger {{ }} ile kacisli; betik yok. --}}
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="noindex">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:locale" content="tr_TR">
@if ($siteName !== '')
    <meta property="og:site_name" content="{{ $siteName }}">
@endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">
</head>
<body>
    <h1>{{ $title }}</h1>
    <p>{{ $description }}</p>
    <p><a href="{{ $url }}">{{ $url }}</a></p>
</body>
</html>
