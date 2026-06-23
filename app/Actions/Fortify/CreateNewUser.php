<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Workspace\WorkspaceProvisioningService;
use App\Settings\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private WorkspaceProvisioningService $provisioning,
        private InstanceSettings $settings,
    ) {}

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! $this->settings->registrationsAllowed(request()->input('invitation'))) {
            throw ValidationException::withMessages([
                'email' => 'Registration is disabled for this instance.',
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->settings->claimOwnerIfMissing($user);
            $this->provisioning->provisionForNewUser($user, request()->input('invitation'));

            return $user->refresh();
        });
    }
}
