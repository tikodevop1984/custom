<?php


namespace NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Abstracts;


use Illuminate\Console\Command;
use NWS\UltraParser\V2\Facade;

/**
 * Class AbstractCommand
 *
 * @package NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Abstracts
 */
abstract class AbstractCLICommand extends Command
{
    /**
     * AbstractCommand constructor.
     */
    public function __construct()
    {
        $this->signature .= '
            {--thread_num= : thread number}
            {--command_id= : command unique id}
            {--main_process : main process flag}
            {--one_time : run only one time}';
        parent::__construct();
    }

    /**
     * Start
     */
    public function handle()
    {
        if ($this->option('main_process')) {
            $commands = $this->generateCommands();
            $multiProcessor = Facade::makeLowLevelModule('MultiProcessor');

            if ($this->isAsync() && !$this->option('one_time')) {
                $multiProcessor->setAsync();
            }

            $multiProcessor->setCommands($commands);
            $multiProcessor->run();

            if (!$this->isAsync() && !$this->option('one_time') && $this->isInfinite()) {
                return $this->handle();
            }

            $this->alert("FINISHED MAIN PROCESS");
            exit;
        }

        try {
            $this->beforeStart();
            $this->alertRunNext();
            $this->start();
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            $this->error($e->getTraceAsString());
            $this->alertKillMe();
        } finally {
            $this->finishCallback();
            $this->alertFinish();
        }
    }

    /**
     * Hack for non blocking sleep
     *
     * @param int $seconds
     * @param int $microseconds
     */
    protected function wait(int $seconds = 0, int $microseconds = 0)
    {
        echo "#WAITING:" . $this->option('command_id') . PHP_EOL;
        if ($seconds) {
            sleep($seconds);
        }
        if ($microseconds) {
            usleep($microseconds);
        }
    }

    /**
     * Alert to run next command from queue
     */
    protected function alertRunNext()
    {
        echo "#QUEUE_NEXT:" . $this->option('command_id') . PHP_EOL;
    }

    /**
     * Alert to run async command
     */
    protected function alertFinish()
    {
        echo "#PROCESS_FINISH:" . $this->option('command_id') . PHP_EOL;
    }

    /**
     * Alert to stop running this command again
     */
    protected function alertKillMe()
    {
        echo "#KILL_ME:" . $this->option('command_id') . PHP_EOL;
    }

    /**
     * Finish callback
     */
    protected function finishCallback(): void
    {
        return;
    }

    /**
     * Generate commands
     *
     * @return array
     */
    abstract protected function generateCommands(): array;

    /**
     * Before start method
     * This method is locking queue
     */
    abstract protected function beforeStart(): void;

    /**
     * Start main job here
     */
    abstract protected function start(): void;

    /**
     * Use async commands
     *
     * @return bool
     */
    abstract protected function isAsync(): bool;

    /**
     * Use infinite queue
     *
     * @return bool
     */
    abstract protected function isInfinite(): bool;
}