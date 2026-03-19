<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Middleware;

use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\RekognitionTask;

class ThrottleRekognitionJobs
{
    public function handle(mixed $job, callable $next): mixed
    {
        $maxConcurrentJobs = config('rekognition.max_concurrent_jobs', 18);
        $currentOpenJobs = RekognitionTask::numberOfOpenJobs();

        if ($currentOpenJobs >= $maxConcurrentJobs) {
            $releaseDelay = config('rekognition.throttle_release_delay', 45);

            logger()->warning('Rekognition job throttled - at capacity', [
                'current_jobs' => $currentOpenJobs,
                'max_jobs'     => $maxConcurrentJobs,
                'release_in'   => "{$releaseDelay} seconds",
                'job_class'    => get_class($job),
                'file_id'      => $job->file->id ?? null,
                'ai_object'    => $job->aiObject ?? null,
            ]);

            $job->release($releaseDelay);

            return null;
        }

        return $next($job);
    }
}
