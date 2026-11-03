<?php

namespace App\Http\Controllers;

use App\Models\ReportTemplate;
use App\Services\ReportRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportTemplateController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $template = ReportTemplate::create($data + ['created_by' => $request->user()->id]);
        $this->enforceSingleDefault($template);

        activity()->causedBy($request->user())->event('created')
            ->log("Created report template {$template->name}");

        return back()->with('success', "Report template {$template->name} created.");
    }

    public function update(Request $request, ReportTemplate $template)
    {
        $template->update($this->validated($request, $template));
        $this->enforceSingleDefault($template);

        activity()->causedBy($request->user())->event('updated')
            ->log("Updated report template {$template->name}");

        return back()->with('success', "Report template {$template->name} saved.");
    }

    public function destroy(Request $request, ReportTemplate $template)
    {
        if ($template->is_default) {
            return back()->withErrors(['template' => 'Set another template as default before deleting this one.']);
        }

        $name = $template->name;
        $template->delete();

        activity()->causedBy($request->user())->event('deleted')
            ->log("Deleted report template {$name}");

        return back()->with('success', "Report template {$name} deleted.");
    }

    public function setDefault(Request $request, ReportTemplate $template)
    {
        ReportTemplate::query()->update(['is_default' => false]);
        $template->forceFill(['is_default' => true])->save();

        activity()->causedBy($request->user())->event('updated')
            ->log("Set {$template->name} as the default report template");

        return back()->with('success', "{$template->name} is now the default template.");
    }

    /** Render the template with a realistic sample CTI record (spec 5.5). */
    public function preview(Request $request, ReportTemplate $template, ReportRenderer $renderer)
    {
        $html = $request->input('html_content', $template->html_content);
        $css = $request->input('css_styles', $template->css_styles);

        // 1. Direct file upload from the preview request if sent as FormData
        $logoUrl = null;
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $mime = $file->getMimeType() ?: 'image/png';
            $logoUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file->getRealPath()));
        }

        // 2. Check if frontend sent the stored path, URL, or data URI
        if (! $logoUrl) {
            $logoInput = $request->input('logo_path')
                ?? $request->input('logo_url')
                ?? $template->logo_path;

            if ($logoInput && ! in_array($logoInput, ['undefined', 'null', ''], true)) {
                $logoUrl = $this->resolveLogoToBase64($logoInput);
            }
        }

        // 3. Fallback: Check if there's any recently uploaded logo file in storage/app/public/logos
        if (! $logoUrl && Storage::disk('public')->exists('logos')) {
            $files = Storage::disk('public')->files('logos');
            if (! empty($files)) {
                // Grab the latest uploaded logo
                $latest = collect($files)->sortByDesc(fn($f) => Storage::disk('public')->lastModified($f))->first();
                if ($latest) {
                    $logoUrl = $this->resolveLogoToBase64($latest);
                }
            }
        }

        // 4. Last resort: only use placeholder if literally no logo file exists
        if (! $logoUrl) {
            $logoUrl = $renderer->placeholderLogo();
        }

        $watermark = $request->input('watermark_text', $template->watermark_text);

        return back()->with('testResult', [
            'type' => 'template_preview',
            'template_id' => $template->id,
            'html' => $renderer->render((string) $html, $renderer->sampleData(), $css, $logoUrl, $watermark),
        ]);
    }

    /** Branding: drag-and-drop logo upload (spec 5.5). */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $path = $request->file('logo')->store('logos', 'public');

        activity()->causedBy($request->user())->event('created')
            ->log("Uploaded report logo {$path}");

        return back()->with('testResult', [
            'type' => 'logo',
            'path' => $path,
            'url' => Storage::url($path),
            'base64' => $this->resolveLogoToBase64($path),
        ]);
    }

    private function resolveLogoToBase64(string $pathOrUrl): ?string
    {
        if (str_starts_with($pathOrUrl, 'data:image/')) {
            return $pathOrUrl;
        }

        $realPath = null;

        if (Storage::disk('public')->exists($pathOrUrl)) {
            $realPath = Storage::disk('public')->path($pathOrUrl);
        } elseif (file_exists(storage_path('app/public/' . ltrim($pathOrUrl, '/')))) {
            $realPath = storage_path('app/public/' . ltrim($pathOrUrl, '/'));
        } elseif (file_exists(public_path($pathOrUrl))) {
            $realPath = public_path($pathOrUrl);
        }

        if ($realPath && file_exists($realPath)) {
            $mime = mime_content_type($realPath) ?: 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($realPath));
        }

        return asset('storage/' . ltrim($pathOrUrl, '/'));
    }

    private function validated(Request $request, ?ReportTemplate $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'html_content' => ['required', 'string', 'max:200000'],
            'css_styles' => ['nullable', 'string', 'max:50000'],
            'watermark_text' => ['nullable', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:512'],
            'is_default' => ['required', 'boolean'],
        ]);
    }

    private function enforceSingleDefault(ReportTemplate $template): void
    {
        if ($template->is_default) {
            ReportTemplate::query()
                ->whereKeyNot($template->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
