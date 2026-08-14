<?php

use App\Http\Controllers\user\tblmenumasterG2gController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ROLE & PERMISSIONS — TOKEN-AUTHENTICATED, AT THE /user PREFIX
|--------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS AT ALL:
|
| routes/user.php is registered in bootstrap/app.php wrapped in
| ['web','auth','session','menu'] — Laravel's WEB SESSION guard, applied to the
| WHOLE FILE. Nothing declared inside it can opt out. The Next.js frontend calls
| these four endpoints with a Bearer token and no session cookie, so `auth`
| rejected the request and returned 403 BEFORE the controller ran. That is the
| 403 an admin saw when saving the rights matrix.
|
| An earlier attempt moved the routes to the bottom of user.php with their own
| middleware. THAT CHANGED NOTHING — the file-level wrapper still applied, and
| the extra middleware simply ran after the guard that was already refusing.
| A route cannot escape a group by being declared further down inside it.
|
| THE CONTROLLER WAS ALWAYS WRITTEN FOR TOKENS: its guard() reads `token`, calls
| PersonalAccessToken::findToken(), and returns 401 on failure. The controller
| expected token auth and the registration enforced session auth. The
| registration won, because it wraps.
|
| THE URL IS UNCHANGED — still /user/... — so the frontend needs no edit. This
| file is registered BEFORE routes/user.php so these definitions win.
|
| `profile:admin` ON THE WRITES, and it is not tidiness. The controller checks
| the token and the tenant but NEVER checks who is calling. Without this, any
| authenticated employee could rewrite any profile's rights, including their
| own. Changing who can see what is an administrator action.
|
| The reads stay `api.token`: seeing the matrix is not changing it, and HR
| legitimately needs to read it.
*/

Route::prefix('user')->group(function () {
    Route::get('ajax_groupwiserights_g2g',
        [tblmenumasterG2gController::class, 'displayGroupwiseRightsG2g'])
        ->middleware('api.token')->name('ajax_groupwiserights_g2g');

    Route::get('ajax_user_profiles_g2g',
        [tblmenumasterG2gController::class, 'displayUserProfilesG2g'])
        ->middleware('api.token')->name('ajax_user_profiles_g2g');

    Route::post('save_groupwiserights_g2g',
        [tblmenumasterG2gController::class, 'storeGroupwiseRightsG2g'])
        ->middleware('profile:admin')->name('save_groupwiserights_g2g');

    Route::post('save_user_profile_g2g',
        [tblmenumasterG2gController::class, 'storeUserProfileG2g'])
        ->middleware('profile:admin')->name('save_user_profile_g2g');
});
