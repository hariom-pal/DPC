@extends('layouts.admin')
@section('title', 'Review Reasons')
@section('content')
    @include('admin.partials.lookup-index', ['items' => $reasons, 'routeBase' => 'admin.review-reasons', 'title' => 'Review Reasons / रिव्यू कारण'])
@endsection
