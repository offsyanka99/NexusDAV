<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sets a new password for an admin from the container shell. Shell access is
 * the proof of ownership; there is no web page and no email. The password is
 * never logged.
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'nexusdav:reset-admin-password
        {--email= : Email of the admin account. May be left out when there is only one admin}
        {--generate : Generate a random password and print it once instead of asking}
        {--revoke-app-passwords : Also revoke every app password of this admin}';

    protected $description = 'Set a new password for an admin account and sign out its sessions';

    private const GENERATED_LENGTH = 24;

    public function handle(UserService $users, AppPasswordService $appPasswords): int
    {
        $admin = $this->admin();

        if (! $admin instanceof User) {
            return self::FAILURE;
        }

        $generated = (bool) $this->option('generate');
        $password = $generated ? Str::password(self::GENERATED_LENGTH, symbols: false) : $this->askPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        try {
            $users->update($admin, ['password' => $password]);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $sessions = $this->endSessions($admin);
        $revoked = $this->shouldRevoke($admin) ? $this->revokeAppPasswords($admin, $appPasswords) : 0;

        Log::warning('Admin password reset from the command line', [
            'username' => $admin->username,
            'sessions_ended' => $sessions,
            'app_passwords_revoked' => $revoked,
        ]);

        $this->info('The password of '.$admin->email.' was changed. Existing sessions are signed out.');

        if ($revoked > 0) {
            $this->info($revoked.' app password(s) revoked.');
        }

        if (! $admin->is_active) {
            $this->warn('This admin account is disabled. Enable it from another admin account before signing in.');
        }

        if ($generated) {
            $this->newLine();
            $this->line('New password (shown once, not stored anywhere else):');
            $this->line($password);
        }

        return self::SUCCESS;
    }

    private function admin(): ?User
    {
        $email = $this->option('email');

        if (is_string($email) && trim($email) !== '') {
            $admin = User::query()
                ->where('is_admin', true)
                ->whereRaw('lower(email) = lower(?)', [trim($email)])
                ->first();

            if (! $admin instanceof User) {
                $this->error('No admin account has that email.');
            }

            return $admin;
        }

        $admins = User::query()->where('is_admin', true)->orderBy('id')->get();

        if ($admins->count() === 1) {
            return $admins->first();
        }

        $this->error($admins->isEmpty()
            ? 'There is no admin account.'
            : 'There is more than one admin. Choose one with --email=.');

        return null;
    }

    private function askPassword(): ?string
    {
        if (! $this->input->isInteractive()) {
            $this->error('No password was given. Run the command interactively, or use --generate.');

            return null;
        }

        $password = (string) $this->secret('New password (at least '.UserService::MIN_PASSWORD_LENGTH.' characters)');

        if (mb_strlen($password) < UserService::MIN_PASSWORD_LENGTH) {
            $this->error('The password must be at least '.UserService::MIN_PASSWORD_LENGTH.' characters.');

            return null;
        }

        if ((string) $this->secret('Repeat the new password') !== $password) {
            $this->error('The passwords do not match. Nothing was changed.');

            return null;
        }

        return $password;
    }

    /**
     * A changed password hash already ends other sessions on their next
     * request (AuthenticateSession). With the database driver the rows are
     * removed as well, so they end now.
     */
    private function endSessions(User $admin): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $admin->getKey())
            ->delete();
    }

    private function shouldRevoke(User $admin): bool
    {
        if ($this->option('revoke-app-passwords')) {
            return true;
        }

        $active = $admin->appPasswords()->whereNull('revoked_at')->count();

        if ($active === 0 || ! $this->input->isInteractive()) {
            return false;
        }

        return $this->confirm('Also revoke this admin\'s '.$active.' app password(s)?', false);
    }

    private function revokeAppPasswords(User $admin, AppPasswordService $appPasswords): int
    {
        $revoked = 0;

        foreach ($admin->appPasswords()->whereNull('revoked_at')->get() as $appPassword) {
            $appPasswords->revoke($appPassword);
            $revoked++;
        }

        return $revoked;
    }
}
