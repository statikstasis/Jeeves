<?php declare(strict_types = 1);

namespace Room11\Jeeves\Plugins;

use Amp\Artax\HttpClient;
use Amp\Artax\Response as HttpResponse;
use Room11\Jeeves\Chat\Command;
use Room11\Jeeves\System\PluginCommandEndpoint;
use Room11\StackChat\Client\Client as ChatClient;

class Chuck extends BasePlugin
{
    private const API_URL = 'http://api.icndb.com/jokes/random/';

    private $chatClient;

    private $httpClient;

    public function __construct(ChatClient $chatClient, HttpClient $httpClient) {
        $this->chatClient = $chatClient;
        $this->httpClient = $httpClient;
    }

    private function getJoke()
    {
        /** @var HttpResponse $response */
        $response = yield $this->httpClient->request(self::API_URL);

        $result = json_try_decode($response->getBody(), true);

        if (!isset($result['type']) && $result['type'] !== 'success') {
            throw new \RuntimeException('Invalid response format');
        }

        return htmlspecialchars_decode($result['value']['joke']);
    }

    public function getChuckJoke(Command $command)
    {
        try {
            $joke = yield from $this->getJoke();
        } catch (\Throwable $e) {
            return $this->chatClient->postReply(
                $command, "Ugh, there was some weird problem while getting the joke."
            );
        }

        return $this->chatClient->postMessage($command, $joke);
    }

    public function getSkeetJoke(Command $command)
    {
        try {
            $joke = str_replace(['Chuck', 'Norris'], ['Jon', 'Skeet'], yield from $this->getJoke());
        } catch (\Throwable $e) {
            return $this->chatClient->postReply(
                $command, "Ugh, there was some weird problem while getting the joke."
            );
        }

        return $this->chatClient->postMessage($command, $joke);
    }

    public function getName(): string
    {
        return 'ChuckSkeet';
    }

    public function getDescription(): string
    {
        return 'Posts a random Chuck Norris/Jon Skeet joke on request';
    }

    /**
     * @return PluginCommandEndpoint[]
     */
    public function getCommandEndpoints(): array
    {
        return [
            new PluginCommandEndpoint('Chuck', [$this, 'getChuckJoke'], 'chuck', 'Posts a random Chuck Norris joke'),
            new PluginCommandEndpoint('Skeet', [$this, 'getSkeetJoke'], 'skeet', 'Posts a random Jon Skeet joke'),
        ];
    }
}
