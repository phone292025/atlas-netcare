@extends('errors.layout')

@section('code', '419')
@section('title', 'That form had expired')
@section('message')
    The page had been open too long for its security token to still be valid, so nothing was saved.
    Go back, and submit it again.
@endsection
{{-- A button with an Alpine handler, not `href="javascript:..."`. A
     javascript: URL is inert under any Content-Security-Policy worth having,
     and this page would then offer a link that silently does nothing — on the
     one screen whose whole job is to tell somebody what went wrong. Alpine is
     already loaded by the error layout. --}}
@section('extra')
    <button type="button"
            x-data
            @click="window.history.back()"
            class="btn btn-secondary">
        Go back
    </button>
@endsection
