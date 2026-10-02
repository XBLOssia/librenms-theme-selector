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

    @php
        // Everything the dropdown and its preview frame need, one entry per choice. `value` is what
        // saving stores ('' follows the instance default); `target` is the skin the preview shows.
        $nameOfDefault = $defaultName ?? 'stock LibreNMS';
        $meta = function (array $s): string {
            $bits = [$s['source'] === 'bundled' ? 'Bundled with the plugin' : 'Uploaded'];
            if (($s['version'] ?? '') !== '') { $bits[] = 'v' . $s['version']; }
            if (($s['author'] ?? '') !== '') { $bits[] = 'by ' . $s['author']; }
            if (! empty($s['installed_at'])) { $bits[] = 'installed ' . substr($s['installed_at'], 0, 10); }
            if (! empty($s['license'])) { $bits[] = $s['license']; }
            return implode(' · ', $bits);
        };
        $choices = [
            ['group' => 'Defaults', 'value' => '', 'label' => 'Instance default (' . $nameOfDefault . ')', 'target' => $default ?? 'none',
             'name' => 'Instance default', 'desc' => 'Follows whatever the administrator sets as the default, now ' . $nameOfDefault . '.', 'meta' => ''],
            ['group' => 'Defaults', 'value' => 'none', 'label' => 'Stock LibreNMS', 'target' => 'none',
             'name' => 'Stock LibreNMS', 'desc' => 'No skin: LibreNMS as it ships.', 'meta' => ''],
        ];
        foreach ($skins as $id => $skin) {
            $choices[] = ['group' => $skin['source'] === 'bundled' ? 'Bundled' : 'Installed', 'value' => $id, 'label' => $skin['name'], 'target' => $id,
                          'name' => $skin['name'], 'desc' => $skin['description'], 'meta' => $meta($skin)];
        }
        $selectedValue = $previewChoice ?? ($choice ?? '');
        $shown = null;
        foreach ($choices as $c) { if ($previewChoice !== null && $c['value'] === $previewChoice) { $shown = $c; } }
        $currentLabel = $choice === null ? 'Instance default (' . $nameOfDefault . ')' : ($choice === 'none' ? 'Stock LibreNMS' : ($skins[$choice]['name'] ?? 'Instance default (' . $nameOfDefault . ')'));
    @endphp

    <style>
        .ts-frame { position:relative; overflow:hidden; border:1px solid rgba(128,128,128,.5); margin:10px 0;
                    background:rgba(128,128,128,.08); aspect-ratio:1280 / 660; width:100%; max-width:1000px; }
        .ts-frame.ts-nojs { width:640px; }
        .ts-frame iframe { position:absolute; left:0; top:0; width:1280px; height:660px; border:0; transform-origin:0 0;
                           transform:scale(var(--ts-scale, .5)); pointer-events:none; }
        .ts-scroll { max-height:26em; overflow:auto; border:1px solid rgba(128,128,128,.4); }
        .ts-scroll table { margin-bottom:0; }
        .ts-scroll thead th { position:sticky; top:0; z-index:1; background:inherit; box-shadow:0 1px 0 rgba(128,128,128,.5); }
        .ts-sort { background:none; border:0; padding:0; font:inherit; color:inherit; cursor:pointer; font-weight:bold; }
        .ts-sort[aria-sort="ascending"]::after { content:" \25B2"; font-size:.75em; }
        .ts-sort[aria-sort="descending"]::after { content:" \25BC"; font-size:.75em; }
        .ts-tools { margin:0 0 8px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .ts-tools[hidden] { display:none; }
    </style>

    <div class="panel panel-default" id="ts-picker">
        <div class="panel-heading"><h3 class="panel-title">Your skin</h3></div>
        <div class="panel-body">
            <p>Your skin now: <strong>{{ $currentLabel }}</strong></p>
            <form method="get" action="{{ route('theme-selector.index') }}#ts-picker" id="ts-choose" class="form-inline">
                <label for="ts-select">Skin</label>
                <select name="preview" id="ts-select" class="form-control" style="min-width:18em; max-width:100%">
                    @foreach(collect($choices)->groupBy('group') as $group => $list)
                        <optgroup label="{{ $group }}">
                            @foreach($list as $c)
                                <option value="{{ $c['value'] }}" @selected($c['value'] === $selectedValue)
                                        data-name="{{ $c['name'] }}" data-desc="{{ $c['desc'] }}" data-meta="{{ $c['meta'] }}"
                                        data-src="{{ route('theme-selector.preview', ['id' => $c['target']]) }}">{{ $c['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-default" id="ts-show">Preview</button>
            </form>

            <div id="ts-preview" @if($shown === null) hidden @endif style="margin-top:12px">
                <h4 id="ts-pv-name" style="margin-bottom:2px">{{ $shown['name'] ?? '' }}</h4>
                <p id="ts-pv-desc" style="margin:0">{{ $shown['desc'] ?? '' }}</p>
                <p id="ts-pv-meta" class="text-muted" style="margin:0">{{ $shown['meta'] ?? '' }}</p>
                <div class="ts-frame ts-nojs" id="ts-frame">
                    <iframe id="ts-iframe" title="Preview of the selected skin" tabindex="-1"
                            @if($shown !== null) src="{{ route('theme-selector.preview', ['id' => $previewTarget]) }}" @endif></iframe>
                </div>
                <form method="post" action="{{ route('theme-selector.store') }}" class="form-inline">
                    @csrf
                    <input type="hidden" name="skin" id="ts-apply-value" value="{{ $shown['value'] ?? '' }}">
                    <button type="submit" class="btn btn-primary" id="ts-apply" @disabled($shown !== null && $shown['value'] === ($choice ?? ''))>Apply to my account</button>
                    <span class="text-muted" id="ts-apply-note">{{ $shown !== null && $shown['value'] === ($choice ?? '') ? 'This is your skin now.' : 'Nothing changes until you apply it.' }}</span>
                </form>
            </div>

            <p class="help-block">
                The preview is a sample page with invented content, always in dark mode. Skins apply in dark mode only:
                with LibreNMS set to Light, pages stay stock. If a skin ever makes a page hard to use, add
                <code>?theme-selector=off</code> to that page's address to see it without any skin, then come back here
                and pick another.
            </p>
        </div>
    </div>
    <script>
    (function () {
        var sel = document.getElementById('ts-select'), box = document.getElementById('ts-preview'),
            frame = document.getElementById('ts-frame'), iframe = document.getElementById('ts-iframe'),
            apply = document.getElementById('ts-apply'), value = document.getElementById('ts-apply-value'),
            note = document.getElementById('ts-apply-note'), current = {!! json_encode($choice ?? '') !!};
        if (!sel || !box || !frame || !iframe) { return; }
        frame.classList.remove('ts-nojs');
        function scale() { frame.style.setProperty('--ts-scale', frame.clientWidth / 1280); }
        function show() {
            var o = sel.options[sel.selectedIndex];
            document.getElementById('ts-pv-name').textContent = o.getAttribute('data-name');
            document.getElementById('ts-pv-desc').textContent = o.getAttribute('data-desc');
            document.getElementById('ts-pv-meta').textContent = o.getAttribute('data-meta');
            box.hidden = false;
            scale();
            if (iframe.getAttribute('src') !== o.getAttribute('data-src')) { iframe.setAttribute('src', o.getAttribute('data-src')); }
            value.value = o.value;
            apply.disabled = (o.value === current);
            note.textContent = apply.disabled ? 'This is your skin now.' : 'Nothing changes until you apply it.';
        }
        sel.addEventListener('change', show);
        document.getElementById('ts-choose').addEventListener('submit', function (e) { e.preventDefault(); show(); });
        window.addEventListener('resize', scale);
        scale();
    })();
    </script>

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
        <div class="panel-heading"><h3 class="panel-title">Installed skins <small>admin</small></h3></div>
        <div class="panel-body">
            <div class="ts-tools" id="ts-tools" hidden>
                <input type="search" id="ts-filter" class="form-control" style="max-width:16em" placeholder="Filter by name, author, id" aria-label="Filter skins">
                <select id="ts-source" class="form-control" style="width:auto" aria-label="Show">
                    <option value="">All skins</option>
                    <option value="bundled">Bundled</option>
                    <option value="uploaded">Uploaded</option>
                </select>
                <select id="ts-size" class="form-control" style="width:auto" aria-label="Rows per page">
                    <option value="10">10 per page</option>
                    <option value="25">25 per page</option>
                    <option value="1000">All</option>
                </select>
                <span class="text-muted" id="ts-count" aria-live="polite"></span>
            </div>
            <div class="ts-scroll">
            <table class="table table-condensed" id="ts-skins">
                <thead>
                    <tr>
                        <th><button type="button" class="ts-sort" data-key="name">Skin</button></th>
                        <th>Id</th>
                        <th><button type="button" class="ts-sort" data-key="author">Author</button></th>
                        <th><button type="button" class="ts-sort" data-key="version">Version</button></th>
                        <th><button type="button" class="ts-sort" data-key="source">Source</button></th>
                        <th><button type="button" class="ts-sort" data-key="installed">Installed</button></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($skins as $id => $skin)
                    <tr data-name="{{ strtolower($skin['name']) }}" data-author="{{ strtolower($skin['author']) }}"
                        data-version="{{ $skin['version'] }}" data-source="{{ $skin['source'] }}"
                        data-installed="{{ $skin['installed_at'] ? strtotime($skin['installed_at'] . ' UTC') : 0 }}"
                        data-search="{{ strtolower($skin['name'] . ' ' . $id . ' ' . $skin['author']) }}">
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
                        <td>
                            @if($skin['installed_at'])
                                <span title="{{ $skin['installed_at'] }}{{ $skin['updated_at'] && $skin['updated_at'] !== $skin['installed_at'] ? ', replaced ' . $skin['updated_at'] : '' }} (server time)">{{ substr($skin['installed_at'], 0, 10) }}</span>
                            @else
                                <span class="text-muted" title="Ships with the plugin">-</span>
                            @endif
                        </td>
                        <td class="text-right" style="white-space:nowrap">
                            <a href="{{ route('theme-selector.index', ['preview' => $id]) }}#ts-picker" class="btn btn-default btn-xs">Preview</a>
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
            </div>
            <div class="ts-tools" id="ts-pager" hidden style="margin:8px 0 18px">
                <button type="button" class="btn btn-default btn-xs" id="ts-prev">Previous</button>
                <span id="ts-page" class="text-muted"></span>
                <button type="button" class="btn btn-default btn-xs" id="ts-next">Next</button>
            </div>
            <script>
            (function () {
                var table = document.getElementById('ts-skins');
                if (!table) { return; }
                var body = table.tBodies[0], rows = Array.prototype.slice.call(body.rows),
                    tools = document.getElementById('ts-tools'), pager = document.getElementById('ts-pager'),
                    filter = document.getElementById('ts-filter'), source = document.getElementById('ts-source'),
                    size = document.getElementById('ts-size'), count = document.getElementById('ts-count'),
                    pageLabel = document.getElementById('ts-page'), prev = document.getElementById('ts-prev'),
                    next = document.getElementById('ts-next'), buttons = table.querySelectorAll('.ts-sort'),
                    key = 'name', dir = 1, page = 0;
                tools.hidden = false; pager.hidden = false;
                function cmp(a, b) {
                    var x = a.getAttribute('data-' + key) || '', y = b.getAttribute('data-' + key) || '', r;
                    if (key === 'installed') { r = (+x) - (+y); }
                    else { r = x.localeCompare(y, undefined, { numeric: true }); }
                    return (r || a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'))) * dir;
                }
                function draw() {
                    var q = filter.value.trim().toLowerCase(), src = source.value, per = parseInt(size.value, 10),
                        list = rows.filter(function (r) {
                            return (!q || r.getAttribute('data-search').indexOf(q) !== -1) && (!src || r.getAttribute('data-source') === src);
                        }).sort(cmp);
                    var pages = Math.max(1, Math.ceil(list.length / per));
                    if (page >= pages) { page = pages - 1; }
                    rows.forEach(function (r) { r.hidden = true; });
                    list.slice(page * per, page * per + per).forEach(function (r) { r.hidden = false; body.appendChild(r); });
                    count.textContent = list.length + ' of ' + rows.length + ' skins';
                    pageLabel.textContent = 'Page ' + (page + 1) + ' of ' + pages;
                    prev.disabled = page === 0; next.disabled = page >= pages - 1;
                    Array.prototype.forEach.call(buttons, function (b) {
                        if (b.getAttribute('data-key') === key) { b.setAttribute('aria-sort', dir > 0 ? 'ascending' : 'descending'); }
                        else { b.removeAttribute('aria-sort'); }
                    });
                }
                Array.prototype.forEach.call(buttons, function (b) {
                    b.addEventListener('click', function () {
                        var k = b.getAttribute('data-key');
                        dir = (k === key) ? -dir : (k === 'installed' ? -1 : 1); key = k; page = 0; draw();
                    });
                });
                [filter, source, size].forEach(function (el) { el.addEventListener('input', function () { page = 0; draw(); }); });
                prev.addEventListener('click', function () { page--; draw(); });
                next.addEventListener('click', function () { page++; draw(); });
                draw();
            })();
            </script>

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
