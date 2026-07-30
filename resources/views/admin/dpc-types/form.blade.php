@extends('layouts.admin')
@section('title', 'DPC Type')
@section('content')
    @include('admin.partials.lookup-form', ['model' => $dpcType, 'routeBase' => 'admin.dpc-types', 'title' => 'DPC Type', 'statuses' => $statuses])
@endsection
