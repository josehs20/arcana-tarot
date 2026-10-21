@extends('layouts.app')

@section('title', 'Recuperar senha')

@section('content')
    <h1>Recuperar senha</h1>

    @if (session('status'))
        <p>{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        </div>
        <button type="submit">Enviar link de redefinição</button>
    </form>

    <p><a href="{{ route('login') }}">Voltar para o login</a></p>
@endsection
