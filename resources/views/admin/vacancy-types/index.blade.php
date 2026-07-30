@extends('layouts.admin')
@section('title', 'Vacancy Types')
@section('content')
    @include('admin.partials.lookup-index', ['items' => $vacancyTypes, 'routeBase' => 'admin.vacancy-types', 'title' => 'Vacancy Types / रिक्ति प्रकार'])
@endsection
