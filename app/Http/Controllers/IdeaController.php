<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\MissionTask;
use App\Services\ChiefOfStaff;
use App\Services\MissionSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class IdeaController extends Controller
{
    public function index(MissionSettings $settings): View
    {
        return view('mission.ideas', [
            'run' => Goal::query()->where('source', 'ideas')->latest('id')->first(),
            'tasks' => MissionTask::query()
                ->whereIn('goal_id', Goal::query()->select('id')->where('source', 'ideas'))
                ->with('lead:id,key,name,position')
                ->latest('id')
                ->limit(30)
                ->get(),
            'ideasEnabled' => (bool) $settings->all()['ideas_enabled'],
        ]);
    }

    public function store(ChiefOfStaff $chiefOfStaff): RedirectResponse
    {
        $chiefOfStaff->propose(true);

        return to_route('ideas.index')->with('status', 'Asked the leads for ideas. They will appear here shortly.');
    }
}
