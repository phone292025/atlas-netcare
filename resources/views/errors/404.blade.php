@extends('errors.layout')

@section('code', '404')
@section('title', 'That page does not exist')
@section('message')
    The address may have been typed by hand, or the record it pointed at has been retired or removed.
    Records are never deleted here, so a ticket or inspection you remember is still reachable from its list.
@endsection
