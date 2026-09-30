@extends('layouts.librenmsv1')

@section('title', 'Theme Selector')

@section('content')
<div class="container">
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    @if(session('upload_errors'))
        <div class="alert alert-danger">
            <strong>That skin was not installed.</strong> Nothing was changed.
            <ul>
                @foreach(session('upload_errors') as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Your skin</h3></div>
        <div class="panel-body">
            <form method="post" action="{{ route('theme-selector.store') }}">
                @csrf
                <div class="radio">
                    <label>
                        <input type="radio" name="skin" value="" @checked($choice === null)>
                        Instance default <span class="text-muted">({{ $defaultName ?? 'stock LibreNMS' }})</span>
                    </label>
                </div>
                <div class="radio">
                    <label><input type="radio" name="skin" value="none" @checked($choice === 'none')> Stock LibreNMS</label>
                </div>
                @foreach($skins as $id => $skin)
                    <div class="radio">
                        <label>
                            <input type="radio" name="skin" value="{{ $id }}" @checked($choice === $id)>
                            {{ $skin['name'] }}@if($skin['description'])<span class="text-muted">: {{ $skin['description'] }}</span>@endif
                        </label>
                    </div>
                @endforeach
                <p class="help-block">
                    Skins apply in dark mode. With LibreNMS set to Light, pages stay stock.
                    If a skin ever makes a page hard to use, add <code>?theme-selector=off</code> to that page's
                    address to see it without any skin, then come back here and pick another.
                </p>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
    </div>

    @can('admin')
    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Instance default <small>admin</small></h3></div>
        <div class="panel-body">
            <p>Applies to users who haven't chosen a skin, and to the login page. Graphs follow each user's
               own skin; the default skin's graph palette is what LibreNMS stores, so it also applies to users
               who follow the default and to graphs no logged-in user requested (API, reports).</p>
            <form method="post" action="{{ route('theme-selector.default') }}" class="form-inline">
                @csrf
                <select name="default" class="form-control">
                    <option value="" @selected($default === null)>None (stock LibreNMS)</option>
                    @foreach($skins as $id => $skin)
                        <option value="{{ $id }}" @selected($default === $id)>{{ $skin['name'] }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-default">Set default</button>
            </form>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Custom skins <small>admin</small></h3></div>
        <div class="panel-body">
            <table class="table table-condensed">
                <thead><tr><th>Skin</th><th>Id</th><th>Author</th><th>Version</th><th>Source</th><th></th></tr></thead>
                <tbody>
                @foreach($skins as $id => $skin)
                    <tr>
                        <td>{{ $skin['name'] }}</td>
                        <td><code>{{ $id }}</code></td>
                        <td>{{ $skin['author'] ?: '-' }}</td>
                        <td>{{ $skin['version'] ?: '-' }}</td>
                        <td>
                            {{ $skin['source'] === 'bundled' ? 'Bundled' : 'Uploaded' }}
                            @if(! empty($skin['license']))<span class="text-muted">({{ $skin['license'] }})</span>@endif
                            @if(! empty($skin['textures']))
                                <div class="text-muted">
                                    Textures:
                                    @foreach($skin['textures'] as $tx)
                                        <code>{{ $tx['name'] ?? '' }}</code> {{ (int) ($tx['width'] ?? 0) }}&times;{{ (int) ($tx['height'] ?? 0) }}{{ ! $loop->last ? ',' : '' }}
                                    @endforeach
                                </div>
                            @endif
                            @if(! empty($skin['license_text']))
                                {{-- Shown as escaped text inside <pre>: the notice is data, never markup or a served file. --}}
                                <details>
                                    <summary>Licence notice</summary>
                                    <pre style="max-height:16em; overflow:auto; white-space:pre-wrap;">{{ $skin['license_text'] }}</pre>
                                </details>
                            @endif
                        </td>
                        <td class="text-right">
                            @if($skin['source'] === 'uploaded')
                                <form method="post" action="{{ route('theme-selector.delete', ['id' => $id]) }}" style="display:inline"
                                      onsubmit="return confirm('Remove this skin? Anyone using it goes back to the instance default.');">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-xs">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if($uploadsAvailable)
                <style>
                    .ts-drop { display:block; margin:0 0 10px; padding:22px 16px; text-align:center; font-weight:normal;
                               border:2px dashed var(--ts-info, #337ab7); border-radius:4px; cursor:pointer; }
                    .ts-drop:hover, .ts-drop:focus-within, .ts-drop.ts-over { border-style:solid; background:rgba(128,128,128,.14); }
                    .ts-drop.ts-picked { border-style:solid; }
                    .ts-drop.ts-bad { border-color:#d9534f; }
                    .ts-drop input { position:absolute; width:1px; height:1px; opacity:0; overflow:hidden; }
                    .ts-drop .ts-big { display:block; font-size:1.15em; margin-bottom:4px; }
                    .ts-step { display:inline-block; min-width:1.6em; margin-right:.4em; padding:0 .4em; border-radius:1em;
                               border:1px solid currentColor; text-align:center; font-size:.9em; }
                    .ts-file { display:block; margin-top:6px; }
                </style>
                <h4>Add a skin</h4>
                <form method="post" action="{{ route('theme-selector.upload') }}" enctype="multipart/form-data"
                      id="ts-upload" data-max-mb="{{ $uploadLimit }}">
                    @csrf
                    <p><span class="ts-step">1</span><strong>Choose a skin bundle</strong>
                       <span class="text-muted">then</span>
                       <span class="ts-step">2</span><strong>Install it</strong></p>
                    <label class="ts-drop" id="ts-drop" for="ts-file">
                        <input type="file" id="ts-file" name="bundle" accept=".zip,application/zip" required>
                        <span class="ts-big"><span class="ts-step">1</span>Drop a <code>.zip</code> here, or click to browse</span>
                        <span class="text-muted" id="ts-hint">Up to {{ $uploadLimit }} MB (this server's PHP allows {{ $phpLimit }}). Nothing is installed until you press the button below.</span>
                        <span class="ts-file" id="ts-file-name" aria-live="polite"></span>
                    </label>
                    <button type="submit" class="btn btn-primary" id="ts-go" disabled><span class="ts-step">2</span>Install skin</button>
                    <span class="text-muted" id="ts-go-hint">Choose a file first.</span>
                    <p class="help-block">
                        The bundle holds <code>skin.json</code> and <code>skin.css</code>, and optionally
                        <code>graph.conf</code>, <code>LICENSE.txt</code> and <code>fonts/*.woff2</code>. It is checked strictly and
                        re-generated before anything is published: only known colour, type and spacing values are accepted,
                        never selectors, images, imports or scripts. Installing a skin doesn't change what anyone sees
                        until they choose it.
                    </p>
                </form>
                <script>
                (function () {
                    var form = document.getElementById('ts-upload'), drop = document.getElementById('ts-drop'),
                        input = document.getElementById('ts-file'), go = document.getElementById('ts-go'),
                        name = document.getElementById('ts-file-name'), goHint = document.getElementById('ts-go-hint'),
                        max = parseFloat(form.getAttribute('data-max-mb')) * 1024 * 1024;
                    function show() {
                        var f = input.files && input.files[0], problem = '';
                        drop.classList.remove('ts-bad', 'ts-picked');
                        if (!f) { name.textContent = ''; go.disabled = true; goHint.textContent = 'Choose a file first.'; return; }
                        if (!/\.zip$/i.test(f.name)) problem = 'That is not a .zip file.';
                        else if (max && f.size > max) problem = 'That file is larger than the ' + (max / 1048576) + ' MB limit.';
                        name.textContent = f.name + ' (' + (f.size / 1024 < 1024 ? Math.max(1, Math.round(f.size / 1024)) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB') + ')';
                        if (problem) {
                            drop.classList.add('ts-bad'); name.textContent += ' - ' + problem;
                            go.disabled = true; goHint.textContent = 'Choose a different file.';
                        } else {
                            drop.classList.add('ts-picked'); go.disabled = false; goHint.textContent = 'Ready. Press Install skin to upload it.';
                        }
                    }
                    input.addEventListener('change', show);
                    ['dragenter', 'dragover'].forEach(function (t) {
                        drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('ts-over'); });
                    });
                    ['dragleave', 'dragend'].forEach(function (t) {
                        drop.addEventListener(t, function () { drop.classList.remove('ts-over'); });
                    });
                    drop.addEventListener('drop', function (e) {
                        e.preventDefault(); drop.classList.remove('ts-over');
                        var files = e.dataTransfer && e.dataTransfer.files;
                        if (files && files.length) {
                            try { input.files = files; } catch (err) { return; }
                            show();
                        }
                    });
                    // A file dropped anywhere else on the page must not navigate away from it.
                    window.addEventListener('dragover', function (e) { if (!drop.contains(e.target)) { e.preventDefault(); } });
                    window.addEventListener('drop', function (e) { if (!drop.contains(e.target)) { e.preventDefault(); } });
                    form.addEventListener('submit', function () { go.disabled = true; goHint.textContent = 'Uploading and checking...'; });
                    show();
                })();
                </script>
            @else
                <p class="text-muted">Uploading skins needs PHP's zlib extension, which this server doesn't have.</p>
            @endif
        </div>
    </div>
    @endcan
</div>
@endsection
