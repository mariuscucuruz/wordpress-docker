<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Commands;

use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Actions\NataeroFetcher;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

class NataeroFetchResults extends Command
{
    use Loggable;

    public const string SIGNATURE = 'nataero:fetch:results';

    protected $signature = self::SIGNATURE . ' {--type=} {--fileID=} {--sync} {--force} {--cleanUpCheckingResultStatus}';

    protected $description = 'Fetch Nataero Results.';

    public function handle(): int
    {
        $this->startLog();

        $functions = config('nataero.functions');
        $functions = [
            NataeroFunctionType::CONVERT->value,
            NataeroFunctionType::MEDIAINFO->value,
            NataeroFunctionType::EXIF->value,
            NataeroFunctionType::SNEAKPEEK->value,
            ...$functions,
        ];

        $option = $this->option('type');

        if ($option && $option !== 'all') {
            $functions = [$this->option('type')];
        }

        collect($functions)->unique()->each(function ($function) {
            $this->log(config('nataero.key_prefix') .
                "FETCHING RESULTS FOR FUNCTION TYPE ({$function})"
            );
            $function = NataeroFunctionType::from(strtoupper($function));
            NataeroFetcher::query($function, (bool) $this->option('force') && ! $this->option('sync'))
                ->when($this->option('fileID'), function ($query, $filter) {
                    return $query->where('file_id', $filter);
                })
                ->each(function ($task) use ($function) {
                    NataeroFetcher::dispatcher($task, $function, $this->option('sync'));

                    $this->log(config('nataero.key_prefix') .
                        "CHECKING RESULT FOR ID ({$task->id})" .
                        ($this->option('sync') ? ' [sync]' : ' [queued]')
                    );
                });
        });

        if ($this->option('cleanUpCheckingResultStatus')) {
            $this->log(config('nataero.key_prefix') .
                'MARKING Long checking RESULTS AS FAILED FOR FUNCTION TYPES (' . implode(', ', $functions) . ')'
            );
            collect($functions)->unique()->each(function ($function) {
                $this->log(config('nataero.key_prefix') .
                    "CLEANING UP CHECKING RESULTS FOR FUNCTION TYPE ({$function})"
                );
                NataeroFetcher::query($function, true, 2280, 200)
                    ->where('status', NataeroTaskStatus::CHECKING_RESULTS)
                    ->each(function ($task) use ($function) {
                        $task->update(['status' => NataeroTaskStatus::FAILED->value]);

                        $task->file->markFailure(
                            FileOperationName::tryFrom(strtolower($function)),
                            'Marked as failed by manual command execution.'
                        );
                    });
            });
        }

        $this->endLog();

        return self::SUCCESS;
    }
}
