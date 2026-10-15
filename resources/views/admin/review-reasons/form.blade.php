@extends('layouts.admin')
@section('title', __('admin.review_reasons.form.review_reason'))
@section('content')
    @include('admin.partials.lookup-form', ['model' => $reason, 'routeBase' => 'admin.review-reasons', 'title' => 'Review Reason', 'statuses' => $statuses])
@endsection
