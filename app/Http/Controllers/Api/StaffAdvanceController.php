<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffAdvance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffAdvanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StaffAdvance::with('staff:id,name,role')->latest('given_date');

        if ($request->has('staff_id')) {
            $query->where('staff_id', $request->staff_id);
        }
        if ($request->has('month')) {
            $query->where('repayment_month', $request->month);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_id'        => 'required|exists:staff,id',
            'amount'          => 'required|integer|min:1',
            'given_date'      => 'required|date',
            'repayment_month' => 'required|date_format:Y-m',
            'reason'          => 'nullable|string|max:300',
            'notes'           => 'nullable|string|max:500',
        ]);

        $advance = StaffAdvance::create($data);
        $advance->load('staff:id,name,role');

        return response()->json($advance, 201);
    }

    public function destroy(StaffAdvance $staffAdvance): JsonResponse
    {
        $staffAdvance->delete();
        return response()->json(null, 204);
    }
}
