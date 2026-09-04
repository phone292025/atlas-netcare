@extends('errors.layout')

@section('code', '419')
@section('title', 'That form had expired')
@section('message')
    The page had been open too long for its security token to still be valid, so nothing was saved.
    Go back, and submit it again.
@endsection
@section('extra')
    <a href="javascript:history.back()" class="rounded-md bg-white px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Go back</a>
@endsection
