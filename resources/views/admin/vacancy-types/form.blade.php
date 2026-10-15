@extends('layouts.admin')
@section('title', __('ui.vacancy_type'))
@section('content')
    @include('admin.partials.lookup-form', ['model' => $vacancyType, 'routeBase' => 'admin.vacancy-types', 'title' => 'Vacancy Type', 'statuses' => $statuses])
@endsection
