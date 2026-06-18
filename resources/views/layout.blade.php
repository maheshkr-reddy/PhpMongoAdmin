<!doctype html>
<html lang="{{ $lang }}" data-theme="{{ $theme }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $appName }}{{ $titleSuffix }}</title>
<meta name="csrf" content="{{ $csrf }}">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div id="topbar">
  <button id="navi-toggle" class="navi-toggle" type="button" aria-label="Toggle navigation" aria-controls="navi" aria-expanded="false">&#9776;</button>
  <div class="brand"><a href="?">{{ $appName }}</a></div>
  <div class="server-pill">&#127807; {{ $host }}</div>
  <div class="spacer"></div>
  @if($username !== '')<span class="server-pill">{{ $username }}</span>@endif
  <a class="logout" href="?do=logout">{{ $logoutLabel }}</a>
</div>
<div id="frame">
  <nav id="navi">@include('partials.navi')</nav>
  <main id="content">
    @include('partials.breadcrumb')
    @if($flash)<div class="alert alert-{{ $flash['type'] }}">{{ $flash['msg'] }}</div>@endif
    @if($coll !== '')
      @include('partials.tabs')
    @elseif($db !== '')
      @include('partials.db_tabs')
    @endif
    @include($inner)
  </main>
</div>
<script src="assets/app.js"></script>
</body>
</html>
