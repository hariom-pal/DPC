@extends('layouts.admin')
@section('title', 'Committee Type')
@section('content')
    @include('admin.partials.lookup-form', ['model' => $committeeType, 'routeBase' => 'admin.committee-types', 'title' => 'Committee Type', 'statuses' => $statuses])
@endsection
