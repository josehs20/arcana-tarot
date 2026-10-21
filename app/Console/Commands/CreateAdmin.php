<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'arcana:create-admin {--name=} {--email=} {--password=}';

    protected $description = 'Cria um usuário administrador sem senha fixa no repositório';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nome');
        $email = $this->option('email') ?: $this->ask('E-mail');

        $password = $this->option('password');
        $generated = false;

        if ($password === null) {
            $password = $this->secret('Senha (deixe em branco para gerar uma aleatória)');
        }

        if ($password === null || $password === '') {
            $password = Str::password(16);
            $generated = true;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Já existe um usuário com o e-mail {$email}.");

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::Admin->value,
            'is_active' => true,
        ]);

        $this->info("Administrador criado: {$user->name} <{$user->email}>");

        if ($generated) {
            $this->warn("Senha gerada (guarde-a agora): {$password}");
        }

        return self::SUCCESS;
    }
}
