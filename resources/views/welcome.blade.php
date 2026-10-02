{{-- Placeholder dashboard so the shell has a home. PTY-12 replaces this file with the real dashboard. --}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <x-empty-state title="Dashboard arrives in PTY-12" text="The shell, design system and components are in place. The KPIs, stock table and open orders come with the UI pages." icon="dashboard" />
@endsection
