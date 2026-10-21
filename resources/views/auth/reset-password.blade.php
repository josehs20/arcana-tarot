@extends('layouts.app')

@section('title', 'Redefinir senha')

@section('content')
    <h1>Redefinir senha</h1>

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div>
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required>
        </div>
        <div>
            <label for="password">Nova senha</label>
            <input id="password" type="password" name="password" required>
        </div>
        <div>
            <label for="password_confirmation">Confirmar nova senha</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required>
        </div>
        <button type="submit">Redefinir senha</button>
    </form>
@endsection
