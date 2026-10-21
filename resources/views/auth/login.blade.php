@extends('layouts.app')

@section('title', 'Entrar')

@section('content')
    <h1>Entrar</h1>

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        </div>
        <div>
            <label for="password">Senha</label>
            <input id="password" type="password" name="password" required>
        </div>
        <div>
            <label><input type="checkbox" name="remember"> Lembrar de mim</label>
        </div>
        <button type="submit">Entrar</button>
    </form>

    <p><a href="{{ route('password.request') }}">Esqueceu a senha?</a></p>
    <p><a href="{{ route('register') }}">Criar conta</a></p>
@endsection
