@extends('errors.layout')

@section('code', '403')
@section('title', 'You cannot open this')
@section('message')
    {{-- The message an abort(403, '...') carried, when there is one. The role
         middleware and the policies write these to be read by a person. --}}
    {{ filled($exception?->getMessage()) ? $exception->getMessage() : 'This part of Atlas NetCare is not available to your role.' }}
    Your sidebar lists everything you can reach.
@endsection
