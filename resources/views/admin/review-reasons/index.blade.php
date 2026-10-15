@extends('layouts.admin')
@section('title', __('admin.review_reasons.index.review_reasons'))
@section('content')
    @include('admin.partials.lookup-index', ['master' => 'review-reasons', 'items' => $reasons, 'routeBase' => 'admin.review-reasons', 'title' => 'Review Reasons / रिव्यू कारण'])
@endsection
