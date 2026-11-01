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
        $logoUrl = $template->logo_path ? Storage::url($template->logo_path) : null;

        return back()->with('testResult', [
            'type' => 'template_preview',
            'template_id' => $template->id,
            'html' => $renderer->render((string) $html, $renderer->sampleData(), $css, $logoUrl, $template->watermark_text),
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
        ]);
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
