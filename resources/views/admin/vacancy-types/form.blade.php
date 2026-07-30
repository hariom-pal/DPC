@extends('layouts.admin')
@section('title', 'Vacancy Type')
@section('content')
    @include('admin.partials.lookup-form', ['model' => $vacancyType, 'routeBase' => 'admin.vacancy-types', 'title' => 'Vacancy Type', 'statuses' => $statuses])
@endsection
