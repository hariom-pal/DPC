@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@section('content')
    <h1 class="h4 mb-3">Administration</h1>
    <p class="text-muted">Manage master data and user accounts. Master modules appear here as they are delivered.</p>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-1">Master Data</h2>
                    <p class="text-muted small mb-0">Districts, designations, cadres, pay scales, categories, reservations, committees, recruitment rules.</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-1">User Management</h2>
                    <p class="text-muted small mb-0">Provision Chairman/Member accounts; assign roles; activate/deactivate.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
