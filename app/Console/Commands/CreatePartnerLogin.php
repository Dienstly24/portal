<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Legt einen Login-Account (role=partner) für einen bestehenden Partner an
 * bzw. verknüpft/aktualisiert ihn – damit sich der Partner ins Partnerportal
 * einloggen kann.
 *
 * Beispiel:
 *   php artisan partner:create-login <partner-id> partner@firma.de "Passwort123"
 */
class CreatePartnerLogin extends Command
{
    protected $signature = 'partner:create-login {partner_id} {email} {password} {--name=}';

    protected $description = 'Login-Account für einen Partner anlegen/zurücksetzen (role=partner).';

    public function handle(): int
    {
        $partner = Partner::find($this->argument('partner_id'));
        if (!$partner) {
            $this->error('Partner nicht gefunden.');
            return self::FAILURE;
        }

        $email = trim((string) $this->argument('email'));
        $password = (string) $this->argument('password');
        if (strlen($password) < 8) {
            $this->error('Passwort muss mindestens 8 Zeichen lang sein.');
            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $partner->name);

        $user = User::where('email', $email)->first();
        if ($user) {
            $user->update(['password' => $password, 'role' => 'partner', 'name' => $name]);
        } else {
            $user = User::create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'partner',
                'email_verified_at' => now(),
            ]);
        }

        $partner->update(['user_id' => $user->id]);

        $this->info("Login für Partner „{$partner->name}“ eingerichtet: {$email}");
        return self::SUCCESS;
    }
}
