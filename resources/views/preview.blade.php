@extends('layouts.librenmsv1')

@section('title', 'Skin preview')

{{--
    A sample page for the picker's preview frame (docs/PLUGIN.md, "The picker"). It goes through the
    real layout, so the real navbar and the real stylesheets are there; SkinInjector puts the
    previewed skin on it, in the mode asked for (the controller sets both; nothing in the URL steers
    the injector). Every name and number below is invented.
--}}
@section('javascript')
    <script>document.documentElement.classList.toggle('dark', {{ $mode === 'dark' ? 'true' : 'false' }});</script>
    <style>
        /* The page is shown in a frame nobody can scroll: no scrollbar track beside it. */
        html, body { overflow: hidden !important; }
        .ts-preview-note { margin: 10px 0; }
        .ts-preview-page .panel { margin-bottom: 12px; }
        .ts-preview-graph svg { display: block; width: 100%; height: auto; }
    </style>
@endsection

@section('content')
<div class="container-fluid ts-preview-page">
    <p class="text-muted ts-preview-note">Preview of <strong>{{ $name }}</strong> in {{ $mode }} mode. Every host, number and graph on this page is invented.</p>

    <div class="row">
        <div class="col-md-7">
            <div class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title">core-sw-01.example.net / Te1/0/1 / port_bits</h3></div>
                <div class="panel-body ts-preview-graph">{!! $graph !!}</div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title">Availability</h3></div>
                <div class="panel-body">
                    <p>Total hosts up: 182
                        <span class="label label-success">UP: 176</span>
                        <span class="label label-warning">WARN: 6</span>
                        <span class="label label-danger">DOWN: 2</span>
                        <span class="label label-default">IGNORED: 4</span>
                    </p>
                    <div class="progress"><div class="progress-bar progress-bar-success" style="width:72%">72%</div></div>
                    <div class="alert alert-warning" style="margin-bottom:0">Sensor over limit on dist-sw-11</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title">Alerts</h3></div>
                <table class="table table-hover table-condensed">
                    <thead><tr><th>Time</th><th>Rule</th><th>Host</th><th>State</th></tr></thead>
                    <tbody>
                        <tr><td>10:14:02</td><td><a href="#">Sensor over limit</a></td><td><a href="#">dist-sw-11</a></td><td><span class="label label-warning">warning</span></td></tr>
                        <tr><td>09:58:41</td><td><a href="#">Device down</a></td><td><a href="#">edge-rtr-04</a></td><td><span class="label label-danger">critical</span></td></tr>
                        <tr><td>09:31:17</td><td><a href="#">Recovered</a></td><td><a href="#">core-sw-02</a></td><td><span class="label label-success">ok</span></td></tr>
                        <tr><td>09:02:55</td><td><a href="#">Port flapping</a></td><td><a href="#">lab-sw-07</a></td><td><span class="label label-info">info</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-md-5">
            <div class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title">Controls</h3></div>
                <div class="panel-body">
                    <p>
                        <button type="button" class="btn btn-default">Default</button>
                        <button type="button" class="btn btn-primary">Primary</button>
                        <button type="button" class="btn btn-success">Success</button>
                        <button type="button" class="btn btn-danger">Danger</button>
                    </p>
                    <div class="form-group"><input type="text" class="form-control" value="Search devices" aria-label="Sample input"></div>
                    <ul class="nav nav-tabs" style="margin-bottom:0">
                        <li class="active"><a href="#">Overview</a></li>
                        <li><a href="#">Graphs</a></li>
                        <li><a href="#">Logs <span class="badge">12</span></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
