<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Extension::withoutGlobalScopes()
            ->where('is_active', true)
            ->with('assignable');

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $extensions = $query->get()->map(fn ($ext) => [
            'id' => $ext->id,
            'number' => $ext->number,
            'type' => $ext->type,
            'label' => $ext->label,
            'assigned_name' => $ext->assignable?->name ?? null,
        ]);

        return response()->json(['data' => $extensions]);
    }
}
