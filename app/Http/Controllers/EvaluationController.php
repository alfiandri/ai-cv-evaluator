<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Evaluation;
use App\Jobs\EvaluateCandidateJob;

class EvaluationController extends Controller
{
    /**
     * @header Authorization
     * @header X-Tenant-ID
     * @header Content-Type application/xml
     */
    public function evaluate(Request $request)
    {
        $data = $request->validate([
            'cv_file_id' => 'required|uuid',
            'project_file_id' => 'required|uuid',
            'job_description' => 'required|string',
            'study_case_brief' => 'required|string',
        ]);

        $id = (string) Str::uuid();
        $eval = Evaluation::create(array_merge($data, [
            'id' => $id,
            'status' => 'queued',
            'user_id' => optional(auth()->user())->id,
        ]));

        dispatch(new EvaluateCandidateJob($id));

        return response()->json(['id' => $id, 'status' => 'queued']);
    }

    /**
     * @header Authorization
     * @header X-Tenant-ID
     * @header Content-Type application/xml
     */
    public function result(string $id)
    {
        $tenantId = request()->header('X-Tenant-ID');
        $eval = Evaluation::where('tenant_id', $tenantId)->findOrFail($id);
        if ($eval->status !== 'completed') {
            return response()->json(['id' => $eval->id, 'status' => $eval->status, 'error' => $eval->error]);
        }
        return response()->json([
            'id' => $eval->id,
            'status' => 'completed',
            'result' => $eval->result_json,
        ]);
    }
}
