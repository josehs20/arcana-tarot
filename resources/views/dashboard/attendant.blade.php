@extends('layouts.app')

@section('title', 'Painel — Atendente')

@section('content')
    <h1>Painel do Atendente</h1>
    <p>Olá, {{ auth()->user()->name }}.</p>
    <p>Área do atendente (provisória).</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sair</button>
    </form>
@endsection
