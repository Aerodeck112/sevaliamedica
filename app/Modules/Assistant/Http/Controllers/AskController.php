<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Assistant\Services\Assistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AskController extends Controller
{
    public function __invoke(Request $request, Assistant $assistant, Settings $settings): JsonResponse
    {
        abort_unless((bool) $settings->get('site.assistant', true), 404);
        $origin = $request->headers->get('Origin');
        abort_if($origin && parse_url($origin, PHP_URL_HOST) !== $request->getHost(), 403);

        $question = trim((string) $request->input('q'));
        if ($question === '' || mb_strlen($question) > 300) {
            return response()->json(['error' => 'Scrieți o întrebare scurtă (cel mult 300 de caractere).'], 422);
        }

        // Întrebarea nu se salvează și nu se trimite nicăieri; răspunsul se calculează local.
        return response()->json($assistant->answer($question), 200, ['Cache-Control' => 'no-store']);
    }
}
