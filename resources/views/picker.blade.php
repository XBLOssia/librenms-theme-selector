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
                        <td>{{ $skin['source'] === 'bundled' ? 'Bundled' : 'Uploaded' }}</td>
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
                <h4>Add a skin</h4>
                <form method="post" action="{{ route('theme-selector.upload') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-group">
                        <input type="file" name="bundle" accept=".zip,application/zip" required>
                        <p class="help-block">
                            A <code>.zip</code> of up to {{ $uploadLimit }} MB (this server's PHP allows {{ $phpLimit }}) containing
                            <code>skin.json</code>, <code>skin.css</code>, optionally <code>graph.conf</code> and
                            <code>fonts/*.woff2</code>. The bundle is checked strictly and re-generated before anything is
                            published: only known colour, type and spacing values are accepted, never selectors, images,
                            imports or scripts. Installing a skin doesn't change what anyone sees until they choose it.
                        </p>
                    </div>
                    <button type="submit" class="btn btn-default">Upload and install</button>
                </form>
            @else
                <p class="text-muted">Uploading skins needs PHP's zlib extension, which this server doesn't have.</p>
            @endif
        </div>
    </div>
    @endcan
</div>
@endsection
