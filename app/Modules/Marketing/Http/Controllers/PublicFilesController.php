<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Core\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Modules\Marketing\Services\LlmsTxt;
use Illuminate\Http\Response;

class PublicFilesController extends Controller
{
    public function llms(Settings $settings, LlmsTxt $llms): Response
    {
        abort_unless((bool) $settings->get('marketing.llms_enabled', true), 404);

        return response($llms->render(), 200, ['Content-Type' => 'text/markdown; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600', 'X-Robots-Tag' => 'noindex']);
    }

    public function indexNowKey(string $indexNowKey, Settings $settings): Response
    {
        $key = (string) $settings->get('marketing.indexnow.key', '');
        abort_unless($key !== '' && hash_equals($key, $indexNowKey) && $settings->get('marketing.indexnow.enabled'), 404);

        return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
