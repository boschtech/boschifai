<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** The signed-in user, for the SPA's auth store (see resources/js/store/modules/auth.js). */
class MeController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]]);
    }
}
