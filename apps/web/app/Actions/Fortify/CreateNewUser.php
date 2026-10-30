<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Legal\LegalAcceptances;
use App\Locale\Locales;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly LegalAcceptances $acceptances) {}

    /** @param  array<string, string>  $input */
    public function create(array $input): User
    {
        $locale = Locales::isSupported(App::getLocale()) ? App::getLocale() : Locales::fallback();
        $documents = $this->acceptances->registrationKeys($locale);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'terms' => array_intersect($documents, ['terms', 'privacy']) !== [] ? ['accepted'] : ['nullable'],
            'consent_ru' => in_array('consent-ru', $documents, true) ? ['accepted'] : ['nullable'],
        ])->validate();

        return DB::transaction(function () use ($input, $locale): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'locale' => $locale,
            ]);

            $this->acceptances->acceptRegistration($user, $locale, request());

            return $user;
        });
    }
}
