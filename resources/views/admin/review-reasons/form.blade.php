@extends('layouts.admin')
@section('title', 'Review Reason')
@section('content')
    @include('admin.partials.lookup-form', ['model' => $reason, 'routeBase' => 'admin.review-reasons', 'title' => 'Review Reason', 'statuses' => $statuses])
@endsection
