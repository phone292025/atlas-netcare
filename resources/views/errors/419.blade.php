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
            class="rounded-md bg-white px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
        Go back
    </button>
@endsection
