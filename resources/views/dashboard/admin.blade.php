@extends('layouts.app')

@section('title', 'Painel — Administrador')

@section('content')
    <h1>Painel do Administrador</h1>
    <p>Olá, {{ auth()->user()->name }}.</p>
    <p>Área administrativa (provisória).</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sair</button>
    </form>
@endsection
