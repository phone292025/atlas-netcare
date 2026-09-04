@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many requests')
@section('message')
    That was tried too many times in a short period. Wait a minute, then try once more.
@endsection
