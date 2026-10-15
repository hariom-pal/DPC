@extends('layouts.admin')
@section('title', __('admin.dpc_types.index.dpc_types'))
@section('content')
    @include('admin.partials.lookup-index', ['master' => 'dpc-types', 'items' => $dpcTypes, 'routeBase' => 'admin.dpc-types', 'title' => 'DPC Types / डी.पी.सी. प्रकार'])
@endsection
