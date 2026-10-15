@extends('layouts.admin')
@section('title', __('admin.committee_types.index.committee_types'))
@section('content')
    @include('admin.partials.lookup-index', ['master' => 'committee-types', 'items' => $committeeTypes, 'routeBase' => 'admin.committee-types', 'title' => 'Committee Types / समिति प्रकार'])
@endsection
