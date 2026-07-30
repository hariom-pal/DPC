@extends('layouts.admin')
@section('title', 'Committee Types')
@section('content')
    @include('admin.partials.lookup-index', ['items' => $committeeTypes, 'routeBase' => 'admin.committee-types', 'title' => 'Committee Types / समिति प्रकार'])
@endsection
