@extends('layouts.admin')
@section('title', __('admin.vacancy_types.index.vacancy_types'))
@section('content')
    @include('admin.partials.lookup-index', ['master' => 'vacancy-types', 'items' => $vacancyTypes, 'routeBase' => 'admin.vacancy-types', 'title' => 'Vacancy Types / रिक्ति प्रकार'])
@endsection
