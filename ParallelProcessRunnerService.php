<?php

use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\{LogicException, RuntimeException};

/**
 * Class ParallelProcessRunnerService
 *
 * @package UtilsBundle\Service
 */
class ParallelProcessRunnerService
{
    private const CONSOLE_PATH = '/bin/console';

    /**
     * @var int $maxParallelProcessesCount
     */
    private $maxParallelProcessesCount;

    /**
     * @var int $waitProcessDelay
     */
    private $waitProcessDelay;

    /**
     * @var string $consoleBinPath
     */
    private $consoleBinPath;

    /**
     * @var string $env
     */
    private $env;

    /**
     * @var Collection $processes
     */
    private $processes;

    /**
     * @var bool $isWaiting
     */
    private $isWaiting;

    /**
     * @var string $phpPath
     */
    private $phpPath;

    /**
     * ParallelProcessRunnerService constructor.
     *
     * @param string $projectDir
     * @param string $environment
     * @param int $defaultMaxParallelProcessesCount
     * @param int $waitProcessDelay
     * @param string $phpPath
     */
    public function __construct(
        string $projectDir,
        string $environment,
        int $defaultMaxParallelProcessesCount,
        int $waitProcessDelay,
        string $phpPath
    ) {
        $this->consoleBinPath = $projectDir . self::CONSOLE_PATH;
        $this->env = $environment;
        $this->maxParallelProcessesCount = $defaultMaxParallelProcessesCount;
        $this->waitProcessDelay = $waitProcessDelay;
        $this->isWaiting = false;
        $this->phpPath = $phpPath;
        $this->processes = new ArrayCollection();
    }

    /**
     * Handle command.
     *
     * @param string $command
     * @param array $arguments
     * @param bool $lastCommand If true, than all collected processes will be executed
     * @throws LogicException
     * @throws RuntimeException
     */
    public function handleCommand(string $command, array $arguments = [], bool $lastCommand = false)
    {
        $process = $this->createProcess($command, $arguments);
        $process->start();
        $this->getProcesses()->add($process);

        if ($this->getProcesses()->count() >= $this->maxParallelProcessesCount || $lastCommand) {
            $this->waitProcesses($lastCommand);
        }
    }

    /**
     * Execute single command.
     *
     * @param string $command
     * @param array $arguments
     * @param bool $async
     * @throws LogicException
     * @throws RuntimeException
     */
    public function executeSingleCommand(string $command, array $arguments = [], bool $async = true)
    {
        $process = $this->createProcess($command, $arguments);
        $process->start();

        if ($async === false) {
            while ($process->isRunning()) {
                $this->wait();
            }
        } else {
            $this->getProcesses()->add($process);
        }
    }

    /**
     * Set count of parallel processes to execute.
     *
     * @param int $count
     */
    public function setParallelProcessesCount(int $count)
    {
        $this->maxParallelProcessesCount = $count;
    }

    /**
     * Get processes
     *
     * @codeCoverageIgnore
     *
     * @return Collection
     */
    protected function getProcesses() : Collection
    {
        return $this->processes;
    }

    /**
     * Get isWaiting
     *
     * @codeCoverageIgnore
     *
     * @return bool
     */
    protected function getIsWaiting() : bool
    {
        return $this->isWaiting;
    }

    /**
     * Create new Process.
     *
     * @param string $command
     * @param array $arguments
     * @return Process
     */
    protected function createProcess(string $command, array $arguments) : Process
    {
        foreach ($arguments as $argument) {
            $args[] = escapeshellarg($argument);
        }

        $process = trim($this->phpPath . ' '
            . $this->consoleBinPath . ' '
            . $command . ' '
            . implode(' ', $args ?? []) . ' -e '
            . $this->env);

        return new Process($process);
    }

    /**
     * Wait processes to finish.
     *
     * @param bool $waitAll
     */
    protected function waitProcesses(bool $waitAll = true)
    {
        if ($this->getIsWaiting() === true) {
            return;
        }

        $processes = $this->getProcesses();

        while ($processes->isEmpty() === false) {
            $this->isWaiting = true;

            /** @var Process $process */
            foreach ($processes as $pKey => $process) {
                if ($process->isRunning() === false) {
                    $processes->remove($pKey);
                }
            }

            if ($processes->count() < $this->maxParallelProcessesCount && $waitAll === false) {
                break;
            }

            $this->wait(); # to prevent processor time overloading
        }

        $this->isWaiting = false;
    }

    /**
     * Run sleep for a waitProcessDelay value time.
     *
     * @codeCoverageIgnore
     */
    protected function wait()
    {
        usleep($this->waitProcessDelay);
    }

    /**
     * Ensure that all processes have been finished.
     *
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        while ($this->getIsWaiting()) {
            $this->wait();
        }

        if ($this->getProcesses()->isEmpty() === false) {
            $this->waitProcesses();
        }
    }
}
