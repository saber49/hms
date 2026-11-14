<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::connection('tenant')->table('listen');

        if ($request->filled('kategorie')) $query->where('kategorie', $request->kategorie);
        if ($request->has('aktiv')) $query->where('aktiv', $request->aktiv);

        return response()->json($query->orderBy('kategorie')->orderBy('sortierung')->get());
    }

    public function store(Request $request)
    {
        $data = $request->all();

        $id = DB::connection('tenant')->table('listen')->insertGetId([
            'kategorie' => $data['kategorie'],
            'wert' => $data['wert'],
            'beschreibung' => $data['beschreibung'] ?? null,
            'aktiv' => $data['aktiv'] ?? 1,
            'sortierung' => $data['sortierung'] ?? 0,
        ]);

        return response()->json(['success' => true, 'id' => $id], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->all();

        DB::connection('tenant')->table('listen')->where('id', $id)->update([
            'kategorie' => $data['kategorie'],
            'wert' => $data['wert'],
            'beschreibung' => $data['beschreibung'] ?? null,
            'aktiv' => $data['aktiv'] ?? 1,
            'sortierung' => $data['sortierung'] ?? 0,
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(int $id)
    {
        DB::connection('tenant')->table('listen')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }
}
