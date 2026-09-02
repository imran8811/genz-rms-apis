<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Expense::latest('date')->latest('id');

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }
        if ($request->has('month')) {
            [$year, $month] = explode('-', $request->month);
            $query->whereYear('date', $year)->whereMonth('date', $month);
        }
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'           => 'required|date',
            'category'       => 'required|in:Rent,Utilities,Salary,Maintenance,Supplies,Marketing,Other',
            'description'    => 'required|string|max:500',
            'amount'         => 'required|integer|min:1',
            'payment_method' => 'in:Cash,Card,Bank Transfer',
            'added_by'       => 'nullable|string|max:100',
        ]);

        $expense = Expense::create($data);
        return response()->json($expense, 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'date'           => 'sometimes|date',
            'category'       => 'sometimes|in:Rent,Utilities,Salary,Maintenance,Supplies,Marketing,Other',
            'description'    => 'sometimes|string|max:500',
            'amount'         => 'sometimes|integer|min:1',
            'payment_method' => 'sometimes|in:Cash,Card,Bank Transfer',
            'added_by'       => 'sometimes|nullable|string|max:100',
        ]);

        $expense->update($data);
        return response()->json($expense->fresh());
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();
        return response()->json(null, 204);
    }

    public function summary(Request $request): JsonResponse
    {
        // Single-day view: scope the totals to the requested date. `today` is
        // returned as that day's total so existing consumers keep working.
        if ($request->filled('date')) {
            $date  = $request->date;
            $total = Expense::whereDate('date', $date)->sum('amount');
            $byCategory = Expense::whereDate('date', $date)
                ->select('category', DB::raw('SUM(amount) as total'))
                ->groupBy('category')
                ->orderByDesc('total')
                ->get();

            return response()->json([
                'date'        => $date,
                'total'       => $total,
                'today'       => $total,
                'by_category' => $byCategory,
                'by_day'      => [],
            ]);
        }

        $month = $request->get('month', now()->format('Y-m'));
        [$year, $mon] = explode('-', $month);

        $total = Expense::whereYear('date', $year)
            ->whereMonth('date', $mon)
            ->sum('amount');

        $byCategory = Expense::whereYear('date', $year)
            ->whereMonth('date', $mon)
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $byDay = Expense::whereYear('date', $year)
            ->whereMonth('date', $mon)
            ->select('date', DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $today = Expense::whereDate('date', today())->sum('amount');

        return response()->json([
            'month'       => $month,
            'total'       => $total,
            'today'       => $today,
            'by_category' => $byCategory,
            'by_day'      => $byDay,
        ]);
    }
}
