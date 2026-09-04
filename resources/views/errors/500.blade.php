@extends('errors.layout')

@section('code', '500')
@section('title', 'Something went wrong on our side')
@section('message')
    Atlas NetCare hit an error it could not recover from. Nothing you entered was lost on purpose, but do check before assuming it was saved.
    If this keeps happening, tell an administrator what you were doing and roughly when.
@endsection
