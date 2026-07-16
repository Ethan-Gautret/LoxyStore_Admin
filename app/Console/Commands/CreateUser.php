<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Crée un utilisateur autorisé à accéder au back-office.
 *
 *   php artisan user:create
 *       → mode interactif (demande nom / email / mot de passe)
 *
 *   php artisan user:create --name="Admin" --email=admin@loxystore.fr --password=secret123
 *       → mode non interactif (déploiement / premier compte)
 *
 * Sert à créer le tout premier compte, puisqu'il n'y a pas d'inscription
 * publique. Les comptes suivants se créent depuis Paramètres → Utilisateurs.
 */
class CreateUser extends Command
{
    protected $signature = 'user:create
        {--name= : Nom affiché}
        {--email= : Adresse email (identifiant de connexion)}
        {--password= : Mot de passe (min. 8 caractères)}';

    protected $description = 'Créer un utilisateur du back-office LoxYStore';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nom');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Mot de passe (min. 8 caractères)');

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name'     => ['required', 'string', 'max:255'],
                'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $password, // cast 'hashed' sur le modèle
        ]);

        $this->info("Utilisateur créé : {$user->name} <{$user->email}> (id {$user->id}).");

        return self::SUCCESS;
    }
}
