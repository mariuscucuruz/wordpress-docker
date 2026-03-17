<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Youtube;

use Symfony\Component\Process\Process;
use YoutubeDl\Process\ProcessBuilderInterface;
use Symfony\Component\Process\ExecutableFinder;
use YoutubeDl\Exception\ExecutableNotFoundException;

class ProcessBuilder implements ProcessBuilderInterface
{
    private ExecutableFinder $executableFinder;

    public function __construct(?ExecutableFinder $executableFinder = null)
    {
        $this->executableFinder = $executableFinder ?? new ExecutableFinder;
    }

    public function build(?string $binPath, ?string $pythonPath, array $arguments = []): Process
    {
        if ($binPath === null) {
            $binPath = $this->executableFinder->find('yt-dlp');
        }

        if ($binPath === null) {
            $binPath = $this->executableFinder->find('youtube-dl');
        }

        if ($binPath === null) {
            throw new ExecutableNotFoundException('"yt-dlp" or "youtube-dl" executable was not found. Did you forgot to configure it\'s binary path? ex.: $yt->setBinPath(\'/usr/bin/yt-dlp\') ?.');
        }

        array_unshift($arguments, $binPath);

        if ($pythonPath !== null) {
            array_unshift($arguments, $pythonPath);
        }

        $process = new Process($arguments);
        $process->setTimeout(config('queue.timeout'));

        return $process;
    }
}
