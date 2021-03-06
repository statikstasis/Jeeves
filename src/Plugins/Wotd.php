<?php declare(strict_types=1);

namespace Room11\Jeeves\Plugins;

use Amp\Artax\HttpClient;
use Amp\Artax\Response as HttpResponse;
use Amp\Pause;
use Amp\Promise;
use function Amp\resolve;
use Room11\Jeeves\Chat\Command;
use Room11\Jeeves\Storage\Admin as AdminStorage;
use Room11\Jeeves\Storage\KeyValue as KeyValueStorage;
use Room11\Jeeves\System\PluginCommandEndpoint;
use Room11\StackChat\Client\Client as ChatClient;
use Room11\StackChat\Entities\PostedMessage;
use Room11\StackChat\Room\Room as ChatRoom;
use function Room11\DOMUtils\domdocument_load_html;

final class WotdServiceControl
{
    public $running = true;
}

class Wotd extends BasePlugin
{
    private const API_URL = 'http://www.dictionary.com/wordoftheday/';

    /**
     * @var WotdServiceControl[]
     */
    private $runningServiceControls = [];

    private $chatClient;
    private $httpClient;
    private $adminStorage;
    private $storage;

    public function __construct(
        ChatClient $chatClient,
        HttpClient $httpClient,
        AdminStorage $adminStorage,
        KeyValueStorage $keyValueStorage
    ) {
        $this->chatClient = $chatClient;
        $this->httpClient = $httpClient;
        $this->adminStorage = $adminStorage;
        $this->storage = $keyValueStorage;
    }

    private function getMessage(HttpResponse $response, bool $addTag): string
    {
        $dom = domdocument_load_html($response->getBody());

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' definition-box ')]");

        if ($nodes->length === 0) {
            return 'I dun goofed';
        }

        $tag        = $addTag ? '[tag:wotd]' : '';
        $word       = $nodes->item(0)->getElementsByTagName('strong')->item(0)->textContent;
        $url        = 'http://www.dictionary.com/browse/' . str_replace(" ", "-", $word);
        $definition = $nodes->item(0)->getElementsByTagName('li')->item(0)->textContent;

        return trim(sprintf('%s **[%s](%s)** %s', $tag, $word, $url, $definition));
    }

    private function isServiceEnabled(ChatRoom $room): Promise
    {
        return resolve(function() use ($room) {
            // todo: do we really need this cast? otherwise just return the promise
            //       instead of wrapping and yielding it
            return (bool) yield $this->storage->get('wotdd', $room);
        });
    }

    private function isServiceRunning(ChatRoom $room)
    {
        return isset($this->runningServiceControls[$room->getIdentString()]);
    }

    private function setServiceEnabled(ChatRoom $room, bool $enabled): Promise
    {
        return $this->storage->set('wotdd', $enabled, $room);
    }

    private function setServiceNextRunTime(ChatRoom $room, \DateTimeImmutable $time): Promise
    {
        return $this->storage->set('wotdd-time', $time->getTimestamp(), $room);
    }

    private function getServiceNextRunTime(ChatRoom $room): Promise
    {
        return resolve(function() use ($room) {
            $timestamp = yield $this->storage->get('wotdd-time', $room);

            return $timestamp !== null
                ? new \DateTimeImmutable('@' . $timestamp, new \DateTimeZone('UTC'))
                : null;
        });
    }

    private function getMillisecondsUntilNextServiceMessage(ChatRoom $room): Promise
    {
        return resolve(function() use ($room) {
            /** @var \DateTimeImmutable $nextTime */
            $nextTime = yield $this->getServiceNextRunTime($room);

            $delay = $nextTime !== null
                ? $nextTime->getTimestamp() - \time()
                : 0;

            return $delay * 1000;
        });
    }

    private function postServiceMessageInRoom(ChatRoom $room): Promise
    {
        return resolve(function() use ($room) {
            $previousPinnedMessageId = yield $this->storage->get('wotdd-pin-message-id', $room);

            if (\in_array($previousPinnedMessageId, yield $this->chatClient->getPinnedMessages($room))) {
                yield $this->chatClient->unstarMessage($previousPinnedMessageId, $room);
            }

            /** @var PostedMessage $message */
            $message = yield $this->postWotdMessageInRoom($room, true);

            yield $this->chatClient->pinOrUnpinMessage($message, $room);
            yield $this->storage->set('wotdd-pin-message-id', $message->getId(), $room);

            /** @var \DateTimeImmutable $nextTime */
            $nextTime = yield $this->getServiceNextRunTime($room);
            $now = \time();

            while ($nextTime->getTimestamp() <= $now) {
                $nextTime = $nextTime->modify('+1 day');
            }

            yield $this->setServiceNextRunTime($room, $nextTime);
        });
    }

    private function runServiceForRoom(ChatRoom $room, WotdServiceControl $control): \Generator
    {
        try {
            do {
                try {
                    $delay = yield $this->getMillisecondsUntilNextServiceMessage($room);

                    if ($delay > 0) {
                        yield new Pause($delay);
                    }

                    if ($control->running) {
                        yield $this->postServiceMessageInRoom($room);
                    }
                } catch (\Throwable $e) {
                    yield $this->chatClient->postMessage(
                        $room,
                        "Something unexpected went wrong with the WOTD service: {$e->getMessage()}"
                    );
                }
            } while ($control->running);
        } finally {
            if ($control->running) {
                $this->stopServiceForRoom($room);
            }
        }
    }

    private function startServiceForRoom(ChatRoom $room)
    {
        $ident = $room->getIdentString();

        \assert(
            !$this->isServiceRunning($room),
            new \Exception('Service already running for room ' . $ident)
        );

        $this->runningServiceControls[$ident] = new WotdServiceControl();
        \Amp\resolve($this->runServiceForRoom($room, $this->runningServiceControls[$ident]));
    }

    private function stopServiceForRoom(ChatRoom $room)
    {
        $ident = $room->getIdentString();

        \assert(
            $this->isServiceRunning($room),
            new \Exception('Service not running for room ' . $ident)
        );

        $this->runningServiceControls[$ident]->running = false;
        unset($this->runningServiceControls[$ident]);
    }

    private function parseServiceTime(string $time): \DateTimeImmutable
    {
        $dateTime = new \DateTimeImmutable($time, new \DateTimeZone('UTC'));

        return $dateTime->getTimestamp() < \time()
            ? $dateTime->modify('+1 day')
            : $dateTime;
    }

    private function postWotdMessageInRoom(ChatRoom $room, bool $addTag): Promise
    {
        return \Amp\resolve(function() use($room, $addTag) {
            $response = yield $this->httpClient->request(self::API_URL);

            return yield $this->chatClient->postMessage($room, $this->getMessage($response, $addTag));
        });
    }

    public function fetch(Command $command)
    {
        return $this->postWotdMessageInRoom($command->getRoom(), false);
    }

    public function service(Command $command): \Generator
    {
        $room = $command->getRoom();

        switch ($command->getParameter(0)) {
            case 'on':
                if (!yield $this->adminStorage->isAdmin($room, $command->getUserId())) {
                    return $this->chatClient->postReply($command, "I'm sorry Dave, I'm afraid I can't do that");
                }

                if (!$command->hasParameters(2)) {
                    return $this->chatClient->postReply($command, 'You must specify a time');
                }

                try {
                    $time = $this->parseServiceTime($command->getParameter(1));
                } catch (\Exception $e) {
                    return $this->chatClient->postReply(
                        $command,
                        'Failed to parse ' . $command->getParameter(1) . ' as a valid time'
                    );
                }

                yield $this->setServiceEnabled($room, true);
                yield $this->setServiceNextRunTime($room, $time);

                if (!$this->isServiceRunning($room)) {
                    $this->startServiceForRoom($room);
                }

                return $this->chatClient->postMessage($command, 'Word Of The Day service is now enabled');

            case 'off':
                if (!yield $this->adminStorage->isAdmin($room, $command->getUserId())) {
                    return $this->chatClient->postReply($command, "I'm sorry Dave, I'm afraid I can't do that");
                }

                yield $this->setServiceEnabled($room, false);

                if ($this->isServiceRunning($room)) {
                    $this->stopServiceForRoom($room);
                }

                return $this->chatClient->postMessage($command, 'Word Of The Day service is now disabled');

            case 'status':
                $enabled = yield $this->isServiceEnabled($room);
                $running = $this->isServiceRunning($room);
                $dateTime = yield $this->getServiceNextRunTime($room);
                $time = $dateTime ? $dateTime->format('H:i:s') . ' UTC' : 'undefined';

                if ($enabled && $running) {
                    $state = "enabled and running (run time: {$time})";
                } else if ($enabled && !$running) {
                    $state = "enabled, but not running - something went wrong (run time: {$time})";
                } else if (!$enabled && $running) {
                    $state = 'disabled, but running (this should not happen!)';
                } else {
                    $state = 'disabled and not running';
                }

                return $this->chatClient->postMessage($command, "Word Of The Day service is currently {$state}");
        }

        return $this->chatClient->postReply($command, 'Syntax: ' . $command->getCommandName() . ' on|off|status [frequency]');
    }

    public function getDescription(): string
    {
        return 'Gets the Word Of The Day from dictionary.com';
    }

    /**
     * @return PluginCommandEndpoint[]
     */
    public function getCommandEndpoints(): array
    {
        return [
            new PluginCommandEndpoint('Fetch', [$this, 'fetch'], 'wotd'),
            new PluginCommandEndpoint('Service', [$this, 'service'], 'wotdd'),
        ];
    }

    public function enableForRoom(ChatRoom $room, bool $persist)
    {
        if (yield $this->isServiceEnabled($room)) {
            $this->startServiceForRoom($room);
        }
    }

    public function disableForRoom(ChatRoom $room, bool $persist)
    {
        if ($this->isServiceRunning($room)) {
            $this->stopServiceForRoom($room);
        }
    }
}
