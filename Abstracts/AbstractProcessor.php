<?php


namespace NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Abstracts;


use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Collections\CommandQueue;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Contracts\Listener;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Contracts\Processor as ProcessorContract;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Repositories\CommandRepository;

/**
 * Class AbstractProcessor
 *
 * @package NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Abstracts
 */
abstract class AbstractProcessor implements ProcessorContract
{
    /**
     * Commands repository
     *
     * @var CommandRepository
     */
    protected $repository;

    /**
     * Commands queue
     *
     * @var CommandQueue
     */
    protected $queue;

    /**
     * Listeners array
     *
     * @var Listener[]
     */
    protected $listeners = [];

    /**
     * On process close callback
     *
     * @var callable
     */
    protected $onClose;

    /**
     * Output streams of processes
     *
     * @var array
     */
    protected $streams = [];

    /**
     * Processes
     *
     * @var array
     */
    protected $processes = [];

    /**
     * proc_open returned pipes array
     *
     * @var array
     */
    protected $pipes = [];

    /**
     * Processor constructor.
     *
     * @param CommandQueue $queue
     * @param CommandRepository $repository
     */
    public function __construct(CommandQueue $queue, CommandRepository $repository)
    {
        $this->queue = $queue;
        $this->repository = $repository;
    }

    /**
     * Check message is core
     *
     * @param string $message
     * @return bool
     */
    protected function isCoreMessage(string $message): bool
    {
        foreach ($this->listeners as $listener) {
            if (strpos($message, $listener->getCorePrefix()) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Listen
     */
    public function listen(): void
    {
        $w = null;
        $e = null;

        //Get streams' outputs
        while($this->filterProcesses()) {
            $outs = $this->streams;
            stream_select($outs, $w, $e, 0, 200000);
            foreach ($outs as $out) {
                $message = fgets($out);

                if (empty($message)) {
                    continue;
                }

                if ($this->isCoreMessage($message)) {
                    foreach ($this->listeners as $listener) {
                        $listener->listen($message);
                    }
                } else {
                    echo $message;
                }
            }
        }

        if (!empty($this->streams)) {
            $this->listen();
        }
    }

    /**
     * Filter current processes
     *
     * @return array
     */
    public function filterProcesses(): array
    {
        return array_filter($this->processes, function($proc) {
            if (!is_resource($proc)) {
                return false;
            }
            $result = proc_get_status($proc)['running'];
            if (!$result) {
                $this->close(array_search($proc, $this->processes));
                $this->triggerClose();
            }
            return $result;
        });
    }

    /**
     * Run process
     *
     * @param string $command
     */
    public function runProcess(string $command): void
    {
        $descriptors = [
            0 => ['pipe', 'r'], // STDIN
            1 => ['pipe', 'w']  // STDOUT
        ];
        $this->processes[] = proc_open($command, $descriptors, $pipes);
        $this->streams[] = $pipes[1];
        stream_set_blocking($pipes[1], 0); // Only for UNIX systems
        $this->pipes[] = $pipes;
    }

    /**
     * Close process
     *
     * @param string $id
     * @return int
     */
    public function close(string $id): int
    {
        if (is_resource($this->pipes[$id][0])) {
            fclose($this->pipes[$id][0]);
        }
        if (is_resource($this->pipes[$id][1])) {
            fclose($this->pipes[$id][1]);
        }
        $result = proc_close($this->processes[$id]);

        if (isset($this->streams[$id])) {
            unset($this->streams[$id]);
        }
        if (isset($this->processes[$id])) {
            unset($this->processes[$id]);
        }
        return $result;
    }

    /**
     * Output
     *
     * @param string $message
     */
    public function output(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * @return CommandRepository
     */
    public function getRepository(): CommandRepository
    {
        return $this->repository;
    }

    /**
     * @return CommandQueue
     */
    public function getQueue(): CommandQueue
    {
        return $this->queue;
    }

    /**
     * Init listeners
     */
    public function initListeners(): void
    {
        if (!empty($this->listeners)) {
            foreach ($this->listeners as &$listener) {
                $listener->setProcessor($this);
            }
        }
    }

    /**
     * Add listener
     *
     * @param Listener $listener
     */
    public function addListener(Listener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * On process close callback
     *
     * @param callable $callback
     */
    public function onClose(callable $callback): void
    {
        $this->onClose = $callback;
    }

    /**
     * Trigger close event
     */
    public function triggerClose(): void
    {
        if ($this->onClose !== null) {
            ($this->onClose)($this);
        }
    }
}