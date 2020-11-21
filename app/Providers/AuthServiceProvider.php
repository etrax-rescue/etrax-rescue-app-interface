<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Models\User;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
    }

    private function deleteToken($user)
    {
        $user->token = null;
        $user->token_expiration_date = null;
        $user->save();
    }

    /**
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['auth']->viaRequest('api', function ($request) {
            // Retrieve the Token from the authorization header
            $token = $request->bearerToken();
            if ($token == null) return null;

            // Find the user to whom this token belongs
            $user = User::where('token', hash('sha256', $token))->first();
            if ($user == null) return null;

            // Get the token expiration date and parse it to a datetime object
            $expiration_date = $user->token_expiration_date;
            if ($expiration_date == null) {
                $this->deleteToken($user);
                return null;
            }
            $expiration_date = \Carbon\Carbon::parse($expiration_date);

            // Check if the token expired
            if ($expiration_date->lt(\Carbon\Carbon::now())) {
                $this->deleteToken($user);
                return null;
            }

            // Token is still valid
            return $user;
        });
    }
}
