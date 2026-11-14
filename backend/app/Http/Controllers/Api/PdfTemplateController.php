<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PdfTemplateController extends Controller
{
    /**
     * GET /api/pdf-templates
     * Returns all templates: system templates first, then user templates.
     */
    public function index(Request $request)
    {
        $rows = DB::connection('tenant')->table('pdf_templates')
            ->select('id', 'name', 'description', 'is_system', 'is_default',
                     'preview_emoji', 'accent_color', 'settings', 'created_by',
                     'created_at', 'updated_at')
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->get();

        $templates = $rows->map(function ($r) {
            $r->settings = json_decode($r->settings, true) ?: [];
            $r->is_system = (bool) $r->is_system;
            $r->is_default = (bool) $r->is_default;
            return $r;
        });

        return response()->json([
            'templates' => $templates,
            'system_count' => $templates->where('is_system', true)->count(),
            'custom_count' => $templates->where('is_system', false)->count(),
        ]);
    }

    /**
     * GET /api/pdf-templates/{id}
     */
    public function show(int $id)
    {
        $t = DB::connection('tenant')->table('pdf_templates')->where('id', $id)->first();
        if (!$t) return response()->json(['error' => 'Vorlage nicht gefunden'], 404);
        $t->settings = json_decode($t->settings, true) ?: [];
        $t->is_system = (bool) $t->is_system;
        $t->is_default = (bool) $t->is_default;
        return response()->json($t);
    }

    /**
     * POST /api/pdf-templates
     * Saves a new custom template.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:120',
            'description'   => 'nullable|string|max:255',
            'preview_emoji' => 'nullable|string|max:8',
            'accent_color'  => 'nullable|string|max:16',
            'settings'      => 'required|array',
            'html_template' => 'nullable|string',
        ]);

        $userEmail = optional($request->user())->email;

        $id = DB::connection('tenant')->table('pdf_templates')->insertGetId([
            'name'          => $data['name'],
            'description'   => $data['description'] ?? null,
            'preview_emoji' => $data['preview_emoji'] ?? '📄',
            'accent_color'  => $data['accent_color'] ?? '#1a2744',
            'is_system'     => false,
            'is_default'    => false,
            'settings'      => json_encode($data['settings']),
            'html_template' => $data['html_template'] ?? null,
            'created_by'    => $userEmail,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id], 201);
    }

    /**
     * PUT /api/pdf-templates/{id}
     */
    public function update(Request $request, int $id)
    {
        $t = DB::connection('tenant')->table('pdf_templates')->where('id', $id)->first();
        if (!$t) return response()->json(['error' => 'Vorlage nicht gefunden'], 404);

        // System templates cannot have their core fields modified, but is_default can be toggled
        if ($t->is_system && !$request->has('is_default_only')) {
            // Only allow setting as default
            if ($request->boolean('is_default')) {
                $this->setAsDefault($id);
                return response()->json(['success' => true, 'message' => 'Als Standard gesetzt']);
            }
            return response()->json(['error' => 'System-Vorlagen können nicht bearbeitet werden'], 403);
        }

        $update = [];
        foreach (['name','description','preview_emoji','accent_color','html_template'] as $k) {
            if ($request->has($k)) $update[$k] = $request->input($k);
        }
        if ($request->has('settings')) {
            $update['settings'] = json_encode($request->input('settings'));
        }
        if ($request->has('is_default') && $request->boolean('is_default')) {
            $this->setAsDefault($id);
        }
        $update['updated_at'] = now();

        DB::connection('tenant')->table('pdf_templates')->where('id', $id)->update($update);
        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/pdf-templates/{id}
     */
    public function destroy(int $id)
    {
        $t = DB::connection('tenant')->table('pdf_templates')->where('id', $id)->first();
        if (!$t) return response()->json(['error' => 'Vorlage nicht gefunden'], 404);
        if ($t->is_system) {
            return response()->json(['error' => 'System-Vorlagen können nicht gelöscht werden'], 403);
        }

        DB::connection('tenant')->table('pdf_templates')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Helper: set a template as the default (unsets all others).
     */
    protected function setAsDefault(int $id): void
    {
        DB::connection('tenant')->table('pdf_templates')->update(['is_default' => false]);
        DB::connection('tenant')->table('pdf_templates')->where('id', $id)->update([
            'is_default' => true,
            'updated_at' => now(),
        ]);
    }
}
