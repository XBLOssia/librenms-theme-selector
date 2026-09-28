@extends('layouts.librenmsv1')

@section('title', 'Theme Selector')

@section('content')
<div class="container">
    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Theme Selector</h3></div>
        <div class="panel-body">
            @if($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif
            <form method="post" action="{{ route('theme-selector.store') }}">
                @csrf
                <div class="radio">
                    <label><input type="radio" name="skin" value="" @checked(empty($current))> None (stock LibreNMS)</label>
                </div>
                @foreach($skins as $id => $name)
                    <div class="radio">
                        <label><input type="radio" name="skin" value="{{ $id }}" @checked($current === $id)> {{ $name }}</label>
                    </div>
                @endforeach
                <p class="help-block">The current skins apply in dark mode only.</p>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
    </div>
</div>
@endsection
