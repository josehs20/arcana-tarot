@extends('layouts.app')

@section('title', 'Painel — Cliente')

@section('content')
    <h1>Painel do Cliente</h1>
    <p>Olá, {{ auth()->user()->name }}.</p>
    <p>Área do cliente (provisória).</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sair</button>
    </form>
@endsection
