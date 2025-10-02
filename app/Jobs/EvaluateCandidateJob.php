<?php

namespace App\Jobs;

use App\Models\Evaluation;
use App\Models\UploadedFile;
use App\Services\EvaluationPipeline;
use App\Services\VectorStore;
use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\TenantContext;

class EvaluateCandidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // seconds
    public $tries = 3;

    public function __construct(public string $evaluationId) {}

    public function backoff(): array
    {
        return [5, 15, 45];
    }

    public function handle(EvaluationPipeline $pipeline, VectorStore $vs): void
    {
        $eval = Evaluation::findOrFail($this->evaluationId);
        TenantContext::set($eval->tenant_id); // restore tenant context in worker
        $eval->update(['status' => 'processing', 'error' => null]);

        $vs->upsert('job_description', $eval->job_description, ['evaluation_id' => $eval->id]);
        $vs->upsert('study_case', $eval->study_case_brief, ['evaluation_id' => $eval->id]);
        $vs->ensureRubricSeeded();

        $cv = UploadedFile::findOrFail($eval->cv_file_id);
        $project = UploadedFile::findOrFail($eval->project_file_id);

        $result = $pipeline->run(
            cvText: $cv->text_extracted ?? '',
            projectText: $project->text_extracted ?? '',
            jobContextType: 'job_description',
            studyContextType: 'study_case'
        );

        $eval->update(['status' => 'completed', 'result_json' => $result]);
    }

    public function failed(Throwable $e): void
    {
        $eval = Evaluation::find($this->evaluationId);
        if ($eval) {
            $eval->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }
}
