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
                <p class="help-block">Skins apply in dark mode. With LibreNMS set to Light, pages stay stock.</p>
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
    @endcan
</div>
@endsection
