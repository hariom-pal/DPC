@extends('layouts.admin')
@section('title', 'DPC Types')
@section('content')
    @include('admin.partials.lookup-index', ['items' => $dpcTypes, 'routeBase' => 'admin.dpc-types', 'title' => 'DPC Types / डी.पी.सी. प्रकार'])
@endsection
