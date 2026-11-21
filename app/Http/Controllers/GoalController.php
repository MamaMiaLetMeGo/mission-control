<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Services\ChiefOfStaff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GoalController extends Controller
{
    public function store(Request $request, ChiefOfStaff $chiefOfStaff): RedirectResponse
    {
        $data = $request->validate(['request' => ['required', 'string', 'max:5000']]);
        $goal = $chiefOfStaff->start($data['request']);

        return to_route('goals.show', $goal)->with('status', 'Goal given to the Chief of Staff. Briefs will appear here shortly.');
    }

    public function show(Goal $goal): View
    {
        return view('mission.goal', [
            'goal' => $goal,
            'tasks' => $goal->tasks()->with('lead:id,key,name,position')->get(),
        ]);
    }
}
