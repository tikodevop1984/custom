<?php


namespace NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor;


use NWS\UltraParser\V2\Core\Module\LowLevel;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Collections\CommandQueue;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Commands\Command;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Contracts\Processor as ProcessorContract;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Exceptions\OnlyCLISupported;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Listeners\AsyncListener;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Listeners\Killer;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Listeners\WaitingListener;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Processors\BatchProcessor;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Processors\QueueProcessor;
use NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor\Repositories\CommandRepository;

/**
 * Class Main
 * MultiProcessor module main class
 *
 * @package NWS\UltraParser\V2\Modules\LowLevel\MultiProcessor
 */
class Main extends LowLevel
{
    /**
     * Commands array
     *
     * @var array
     */
    private $commands;

    /**
     * Main commands queue
     *
     * @var CommandQueue
     */
    private $queueCollection;

    /**
     * Commands repository
     *
     * @var CommandRepository
     */
    private $commandsRepository;

    /**
     * Processor
     *
     * @var ProcessorContract
     */
    private $processor;

    /**
     * Process close callback
     *
     * @var callable
     */
    private $onProcessClose;

    /**
     * Async mode
     *
     * @var bool
     */
    private $asyncMode = false;

    /**
     * Queue mode
     *
     * @var bool
     */
    private $queueMode = true;

    /**
     * Infinite queue mode
     *
     * @var bool
     */
    private $infinite = false;

    /**
     * Set commands
     *
     * @param array $commands
     */
    public function setCommands(array $commands): void
    {
        //Init Queue, Repository and commands array
        $this->commands = $commands;
        $this->queueCollection = new CommandQueue();
        $this->commandsRepository = new CommandRepository();

        $commands = $this->generateCommands($commands);
        foreach ($commands as $command) {
            $this->queueCollection->push($command);
            $this->commandsRepository->add($command);
        }
    }

    /**
     * Generate commands
     *
     * @param array $items
     * @return Command[]
     */
    public function generateCommands(array $items): array
    {
        return array_map(function($item) {
            $command = $this->generateSafeCommand($item);
            return $command;
        }, $items);
    }

    /**
     * Generate safe command
     *
     * @param $item
     * @return Command
     */
    public function generateSafeCommand($item): Command
    {
        $signature = is_array($item) ? $item['signature'] : $item;
        $id = is_array($item) ? $item['id'] : uniqid('COMMAND_ID_', true);

        if (strpos($signature, '{$command_id}') !== false) {
            $signature = str_replace('{$command_id}', "\"$id\"", $signature);
        }else {
            $signature .= " --command_id=\"$id\"";
        }
        return new Command($signature, $id);
    }

    /**
     * Set async mode to true
     */
    public function setAsync(): void
    {
        $this->asyncMode = true;
    }

    /**
     * Set queue mode to false (run all commands)
     */
    public function setWithoutQueue(): void
    {
        $this->queueMode = false;
    }

    /**
     * Set queue mode to true
     */
    public function setWithQueue(): void
    {
        $this->queueMode = true;
    }

    /**
     * Set infinite mode
     */
    public function setInfinite(): void
    {
        $this->infinite = true;
    }

    /**
     * Run commands
     *
     * @throws OnlyCLISupported
     */
    public function run(): void
    {
        $this->checkCliMode();
        $this->initProcessor();

        $this->processor->start();

        if ($this->infinite && !$this->asyncMode) {
            $this->setCommands($this->commands);
            $this->run();
        }
    }

    /**
     * Set callback on process close
     *
     * @param callable $callback
     */
    public function setProcessCloseCallback(callable $callback)
    {
        $this->onProcessClose = $callback;
    }

    /**
     * Set processor
     */
    private function initProcessor(): void
    {
        if ($this->queueMode) {
            $this->processor = new QueueProcessor($this->queueCollection, $this->commandsRepository);

            if ($this->asyncMode) {
                $this->processor->addListener(new AsyncListener());
            }
        } else {
            $this->processor = new BatchProcessor($this->queueCollection, $this->commandsRepository);
        }
        $this->processor->addListener(new Killer());
        $this->processor->addListener(new WaitingListener());

        if ($this->onProcessClose !== null) {
            $this->processor->onClose($this->onProcessClose);
        }

        $this->processor->initListeners();
    }

    /**
     * Check php mode
     *
     * @throws OnlyCLISupported
     */
    private function checkCliMode(): void
    {
        if (php_sapi_name() !== 'cli') {
            throw new OnlyCLISupported("Multi processor module works only on CLI mode");
        }
    }
}