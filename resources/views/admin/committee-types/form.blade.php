@extends('layouts.admin')
@section('title', __('ui.committee_type'))
@section('content')
    @include('admin.partials.lookup-form', ['model' => $committeeType, 'routeBase' => 'admin.committee-types', 'title' => 'Committee Type', 'statuses' => $statuses])
@endsection
