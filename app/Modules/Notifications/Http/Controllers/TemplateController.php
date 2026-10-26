<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Services\TemplateRenderer;
use App\Modules\Notifications\Support\MessageType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Șabloanele de mesaje, versionate: fiecare salvare creează o versiune nouă; cele vechi rămân în istoric. */
class TemplateController extends Controller
{
    public function index(): View
    {
        $active = NotificationTemplate::where('is_active', true)->get()
            ->groupBy(fn ($t) => $t->type.'|'.$t->channel)->map(fn ($g) => $g->sortByDesc('version')->first());

        return view('notifications::templates.index', ['types' => MessageType::all(), 'active' => $active]);
    }

    public function edit(Request $request, string $type, string $channel, TemplateRenderer $renderer): View
    {
        $this->assertKnown($type, $channel);
        $current = NotificationTemplate::active($type, $channel);
        $draft = [
            'subject' => $request->old('subject', $current?->subject),
            'body' => $request->old('body', $current?->body ?? ''),
            'provider_template' => $request->old('provider_template', $current?->provider_template),
            'provider_params' => $request->old('provider_params', implode(',', $current?->provider_params ?? [])),
        ];
        $sample = TemplateRenderer::sampleVariables();

        return view('notifications::templates.edit', [
            'type' => $type, 'channel' => $channel, 'meta' => MessageType::all()[$type], 'current' => $current,
            'draft' => $draft, 'variables' => MessageType::variables(),
            'preview' => [
                'subject' => $draft['subject'] ? $renderer->render($draft['subject'], $sample, true) : null,
                'body' => $renderer->render((string) $draft['body'], $sample, $channel !== 'email'),
            ],
            'history' => NotificationTemplate::where('type', $type)->where('channel', $channel)->with('author')->orderByDesc('version')->get(),
        ]);
    }

    public function update(Request $request, string $type, string $channel, TemplateRenderer $renderer): RedirectResponse
    {
        $this->assertKnown($type, $channel);
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:5000'],
            'provider_template' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'provider_params' => ['nullable', 'string', 'max:500'],
            'action' => ['nullable', 'in:preview,save'],
        ]);
        $params = array_values(array_filter(array_map('trim', explode(',', (string) ($data['provider_params'] ?? '')))));
        $errors = $renderer->validate($channel, $data['subject'] ?? null, $data['body'], $params);
        if ($errors) {
            throw ValidationException::withMessages(['body' => $errors]);
        }

        if (($data['action'] ?? 'save') === 'preview') {
            return back()->withInput()->with('status', 'Previzualizarea a fost actualizată. Șablonul nu a fost salvat.');
        }

        $template = DB::transaction(function () use ($type, $channel, $data, $params, $request) {
            $version = (int) NotificationTemplate::where('type', $type)->where('channel', $channel)->lockForUpdate()->max('version') + 1;
            NotificationTemplate::where('type', $type)->where('channel', $channel)->update(['is_active' => false]);

            return NotificationTemplate::create([
                'type' => $type, 'channel' => $channel, 'version' => $version,
                'subject' => $channel === 'email' ? $data['subject'] : null,
                'body' => $data['body'],
                'provider_template' => $channel === 'whatsapp' ? ($data['provider_template'] ?? null) : null,
                'provider_params' => $channel === 'whatsapp' ? $params : null,
                'is_active' => true, 'created_by' => $request->user()->id, 'created_at' => now(),
            ]);
        });
        Audit::log('notifications.template_saved', $template, ['tip' => $type, 'canal' => $channel, 'versiune' => $template->version]);

        return redirect()->route('panou.notifications.templates.edit', [$type, $channel])->with('status', 'Versiunea '.$template->version.' a fost salvată și este activă.');
    }

    public function restore(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $copy = DB::transaction(function () use ($template, $request) {
            $version = (int) NotificationTemplate::where('type', $template->type)->where('channel', $template->channel)->lockForUpdate()->max('version') + 1;
            NotificationTemplate::where('type', $template->type)->where('channel', $template->channel)->update(['is_active' => false]);

            return NotificationTemplate::create($template->only(['type', 'channel', 'subject', 'body', 'provider_template', 'provider_params']) + [
                'version' => $version, 'is_active' => true, 'created_by' => $request->user()->id, 'created_at' => now(),
            ]);
        });
        Audit::log('notifications.template_restored', $copy, ['din_versiunea' => $template->version]);

        return redirect()->route('panou.notifications.templates.edit', [$template->type, $template->channel])
            ->with('status', 'Versiunea '.$template->version.' a fost restaurată ca versiunea '.$copy->version.'.');
    }

    private function assertKnown(string $type, string $channel): void
    {
        abort_unless(isset(MessageType::all()[$type]) && isset(MessageType::CHANNELS[$channel]), 404);
    }
}
