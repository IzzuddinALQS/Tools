<?php

namespace App\Http\Controllers;

use App\Services\Guard\KillSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kill switch global: "Pause semua job" (Keamanan 7).
 */
class PauseController extends Controller
{
    public function index(Request $request, KillSwitch $killSwitch): Response
    {
        return Inertia::render('Pause', [
            'pause' => $killSwitch->state(),
        ]);
    }

    public function store(Request $request, KillSwitch $killSwitch): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $killSwitch->pause($request->user()?->id, $data['reason'] ?? null);

        return back()->with('success', 'Semua job di-PAUSE. Tidak ada penulisan ke PostgreSQL A atau B.');
    }

    public function destroy(Request $request, KillSwitch $killSwitch): RedirectResponse
    {
        $killSwitch->resume($request->user()?->id);

        return back()->with('success', 'Semua job di-resume.');
    }
}
