@extends('layouts.admin')
@section('title', __('admin.dpc_types.form.dpc_type'))
@section('content')
    @include('admin.partials.lookup-form', ['model' => $dpcType, 'routeBase' => 'admin.dpc-types', 'title' => 'DPC Type', 'statuses' => $statuses])
@endsection
