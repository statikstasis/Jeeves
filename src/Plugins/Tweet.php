<?php declare(strict_types=1);

namespace Room11\Jeeves\Plugins;

use Amp\Artax\HttpClient;
use Amp\Artax\Request as HttpRequest;
use Amp\Artax\Response as HttpResponse;
use Amp\Pause;
use PeeHaa\AsyncTwitter\Api\Client\Client as TwitterClient;
use PeeHaa\AsyncTwitter\Api\Client\ClientFactory as TwitterClientFactory;
use PeeHaa\AsyncTwitter\Api\Client\Exception\RequestFailed as TwitterRequestFailedException;
use PeeHaa\AsyncTwitter\Api\Request\Media\Response\UploadResponse;
use PeeHaa\AsyncTwitter\Api\Request\Media\Upload;
use PeeHaa\AsyncTwitter\Api\Request\Status\Retweet as RetweetRequest;
use PeeHaa\AsyncTwitter\Api\Request\Status\Update as UpdateRequest;
use PeeHaa\AsyncTwitter\Credentials\AccessTokenFactory as TwitterAccessTokenFactory;
use Room11\DOMUtils\LibXMLFatalErrorException;
use Room11\Jeeves\Chat\Command;
use Room11\Jeeves\Exception;
use Room11\Jeeves\Storage\Admin as AdminStorage;
use Room11\Jeeves\Storage\KeyValue as KeyValueStore;
use Room11\Jeeves\System\PluginCommandEndpoint;
use Room11\Jeeves\Utf8Chars;
use Room11\StackChat\Client\Client as ChatClient;
use Room11\StackChat\Client\MessageIDNotFoundException;
use Room11\StackChat\Client\MessageResolver as ChatMessageResolver;
use Room11\StackChat\Entities\MainSiteUser;
use Room11\StackChat\Room\Room as ChatRoom;
use function Amp\all;
use function Amp\first;
use function Amp\resolve;
use function Room11\DOMUtils\domdocument_load_html;
use function Room11\DOMUtils\xpath_html_class;

class NotConfiguredException extends Exception {}
class TweetIDNotFoundException extends Exception {}
class TweetLengthLimitExceededException extends Exception {}
class TextProcessingFailedException extends Exception {}
class UnhandledOneboxException extends Exception {}
class MediaProcessingFailedException extends Exception {}

class Tweet extends BasePlugin
{
    private const MAX_TWEET_LENGTH = 280;

    private $chatClient;
    private $admin;
    private $keyValueStore;
    private $apiClientFactory;
    private $accessTokenFactory;
    private $httpClient;
    private $messageResolver;

    /**
     * @var TwitterClient[]
     */
    private $clients = [];

    public function __construct(
        ChatClient $chatClient,
        HttpClient $httpClient,
        AdminStorage $admin,
        KeyValueStore $keyValueStore,
        TwitterClientFactory $apiClientFactory,
        TwitterAccessTokenFactory $accessTokenFactory,
        ChatMessageResolver $messageResolver
    ) {
        $this->chatClient         = $chatClient;
        $this->admin              = $admin;
        $this->keyValueStore      = $keyValueStore;
        $this->apiClientFactory   = $apiClientFactory;
        $this->accessTokenFactory = $accessTokenFactory;
        $this->httpClient         = $httpClient;
        $this->messageResolver    = $messageResolver;
    }

    private function getRawMessage(ChatRoom $room, string $link)
    {
        $messageID = $this->messageResolver->resolveMessageIDFromPermalink($link);

        $messageInfo = yield $this->chatClient->getMessageHTML($room, $messageID);

        $messageBody = html_entity_decode($messageInfo, ENT_QUOTES);
        $messageBody = str_replace(Utf8Chars::ZWNJ . Utf8Chars::ZWS, '', $messageBody);

        return domdocument_load_html($messageBody, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    }

    private function getTweetIdFromMessage(\DOMXPath $xpath): int
    {
        /** @var \DOMElement $element */
        foreach ($xpath->document->getElementsByTagName('a') as $element) {
            if (!preg_match('~https?://twitter\.com/[^/]+/status/(\d+)~', $element->getAttribute('href'), $matches)) {
                continue;
            }

            return (int)$matches[1];
        }

        throw new TweetIDNotFoundException("ID not found");
    }

    private function replaceNode(\DOMNode $old, $new)
    {
        if (!$new instanceof \DOMNode) {
            $new = $old->ownerDocument->createTextNode((string)$new);
        }

        $old->parentNode->replaceChild($new, $old);

        return $new;
    }

    private function downloadMediaForUpload(string $url)
    {
        $request = (new HttpRequest)
            ->setMethod('GET')
            ->setUri($url)
            ->setHeader('Connection', 'close');

        /** @var HttpResponse $response */
        $response = yield $this->httpClient->request($request);
        $tmpFilePath = \Room11\Jeeves\DATA_BASE_DIR . '/' . uniqid('twitter-media-', true);
        yield \Amp\File\put($tmpFilePath, $response->getBody());

        return $tmpFilePath;
    }

    private function anchorTextIsUrl(\DOMElement $element): bool
    {

        if ($element->textContent === $element->getAttribute('href')) {
            return true;
        }

        return mb_substr($element->textContent, -1, 1, 'UTF-8') === Utf8Chars::ELLIPSIS
            && strpos($element->getAttribute('href'), mb_substr($element->textContent, 0, -1, 'UTF-8')) > 0;
    }

    /**
     * @param string $url
     * @return bool|callable
     */
    private function urlIsUploadableMedia(string $url)
    {
        static $types = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'video/mp4',
        ];

        $request = (new HttpRequest)
            ->setMethod('HEAD')
            ->setUri($url)
            ->setHeader('Connection', 'close');

        /** @var HttpResponse $response */
        $response = yield $this->httpClient->request($request);

        return $response->hasHeader('Content-Type') && in_array($response->getHeader('Content-Type')[0], $types);
    }

    private function addExtraAnchors(\DOMText $textNode)
    {
        if (preg_match_all('#(?<=^|\s)https?://\S+#', $textNode->data, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }

        $doc = $textNode->ownerDocument;
        $parts = [];
        $ptr = 0;

        foreach ($matches[0] as [$match, $pos]) {
            $anchor = $doc->createElement('a', $match);
            $anchor->setAttribute('href', $match);

            $text = substr($textNode->data, $ptr, $pos - $ptr);

            $parts[] = $doc->createTextNode($text);
            $parts[] = $anchor;

            $ptr = $pos + strlen($match);
        }

        $parts[] = $doc->createTextNode(substr($textNode->data, $ptr));

        $parent = $textNode->parentNode;
        $next = $textNode->nextSibling;
        $this->replaceNode($textNode, array_shift($parts));

        foreach ($parts as $part) {
            $parent->insertBefore($part, $next);
        }
    }

    private function replaceAnchors(\DOMXPath $xpath)
    {
        // Bare links don't come through as anchors, so we add them
        /** @var \DOMElement $element */
        /** @var \DOMText $textNode */

        foreach ($xpath->query('//text()[not(ancestor::a)]') as $textNode) {
            $this->addExtraAnchors($textNode);
        }

        // First pass - replace [tag] with #tag
        foreach ($xpath->query("//a[span[" . xpath_html_class('ob-post-tag') . "]]") as $element) {
            $parts = preg_split('/[^a-z0-9]+/i', $element->textContent, -1, PREG_SPLIT_NO_EMPTY);
            $tag = count($parts) > 1 ? implode('', array_map('ucfirst', $parts)) : $parts[0];

            $this->replaceNode($element, "#{$tag}");
        }

        $elements = [];
        $headPromises = [];

        // Second pass - normalize hrefs and check if link targets are uploadable media
        foreach ($xpath->query('//a') as $element) {
            $href = $element->getAttribute('href');

            if (substr($href, 0, 2) === '//') {
                $href = 'https:' . $href;
                $element->setAttribute('href', $href);
            }

            if (!isset($headPromises[$href])) {
                $headPromises[$href] = resolve($this->urlIsUploadableMedia($href));
            }

            $elements[] = $element;
        }

        $uploadableMedia = yield first([all($headPromises), new Pause(5000)]);

        if (!is_array($uploadableMedia)) {
            throw new MediaProcessingFailedException;
        }

        $files = [];

        foreach ($elements as $element) {
            // If the link is uploadable media, convert the message to the link text, otherwise use link text and url
            if ($uploadableMedia[$element->getAttribute('href')]) {
                $files[] = resolve($this->downloadMediaForUpload($element->getAttribute('href')));
                $text = $this->anchorTextIsUrl($element)
                    ? '' :
                    $element->textContent;
            } else {
                $text = $this->anchorTextIsUrl($element)
                    ? $element->getAttribute('href')
                    : $element->textContent . ' ' . $element->getAttribute('href');
            }

            $this->replaceNode($element, $text);
        }

        return yield all($files);
    }

    private function replacePings(ChatRoom $room, string $text)
    {
        static $pingExpr = '/@([^\s]+)(?=$|\s)/';

        if (!preg_match_all($pingExpr, $text, $matches)) {
            return $text;
        }

        $pingableIDs = yield $this->chatClient->getPingableUserIDs($room, ...$matches[1]);
        $ids = array_values($pingableIDs);
        $users = yield $this->chatClient->getMainSiteUsers($room, ...$ids);

        /** @var MainSiteUser[] $pingableUsers */
        $pingableUsers = [];
        foreach ($pingableIDs as $name => $id) {
            $pingableUsers[$name] = $users[$id];
        }

        $text = preg_replace_callback($pingExpr, function($match) use($pingableUsers) {
            $handle = isset($pingableUsers[$match[1]])
                ? $pingableUsers[$match[1]]->getTwitterHandle()
                : null;

            return $handle !== null ? '@' . $handle : $match[1];
        }, $text);

        if ($text[0] === '@') {
            $text = '.' . $text;
        }

        return $text;
    }

    private function getClientForRoom(ChatRoom $room)
    {
        $ident = $room->getIdentString();

        if (isset($this->clients[$ident])) {
            return $this->clients[$ident];
        }

        $keys = ['oauth.access_token', 'oauth.access_token_secret'];
        $config = [];

        foreach ($keys as $key) {
            if (!yield $this->keyValueStore->exists($key, $room)) {
                throw new NotConfiguredException('Missing config key: ' . $key);
            }

            $config[$key] = yield $this->keyValueStore->get($key, $room);
        }

        $accessToken = $this->accessTokenFactory->create($config['oauth.access_token'], $config['oauth.access_token_secret']);
        $this->clients[$ident] = $this->apiClientFactory->create($accessToken);

        return $this->clients[$ident];
    }

    private function isOnebox(\DOMXPath $xpath): bool
    {
        return $xpath->query("/div[" . xpath_html_class('onebox') . "]")->length === 1;
    }

    private function attachMediaToUpdateRequest(UpdateRequest $request, ChatRoom $room, string ...$files)
    {
        /** @var TwitterClient $client */
        $client = yield from $this->getClientForRoom($room);
        $ids = [];

        foreach ($files as $file) {
            /** @var UploadResponse $result */
            $result = yield $client->request((new Upload)->setFilePath($file));
            $ids[] = $result->getMediaId();

            yield \Amp\File\unlink($file);
        }

        if (!empty($ids)) {
            $request->setMediaIds(...$ids);
        }
    }

    private function buildUpdateRequestFromOnebox(ChatRoom $room, \DOMXPath $xpath)
    {
        $classList = $xpath->document->documentElement->getAttribute('class');

        if (!preg_match('/\bob-(\S+)/', $classList, $match)) {
            throw new UnhandledOneboxException;
        }

        switch ($match[1]) {
            case 'tweet':
                return new RetweetRequest($this->getTweetIdFromMessage($xpath));

            case 'youtube':
                $url = $xpath->document->getElementsByTagName('a')->item(0)->getAttribute('href');
                return new UpdateRequest($url);

            case 'image':
                $target = $xpath->document->getElementsByTagName('img')->item(0)->getAttribute('src');

                if (substr($target, 0, 2) === '//') {
                    $target = 'https:' . $target;
                }

                $file = yield from $this->downloadMediaForUpload($target);

                $request = new UpdateRequest('');

                yield from $this->attachMediaToUpdateRequest($request, $room, $file);

                return $request;
        }

        throw new UnhandledOneboxException;
    }

    private function buildUpdateRequest(ChatRoom $room, \DOMXPath $xpath)
    {
        $files = yield from $this->replaceAnchors($xpath);

        $text = trim(yield from $this->replacePings($room, $xpath->document->textContent));
        $text = \Normalizer::normalize(trim($text), \Normalizer::FORM_C);

        if ($text === false) {
            throw new TextProcessingFailedException;
        }

        // Strip dot-only messages (anti-onebox hack)
        if ($text === '.') {
            $text = '';
        }

        if (mb_strlen($text, 'UTF-8') > self::MAX_TWEET_LENGTH) {
            throw new TweetLengthLimitExceededException;
        }

        $result = new UpdateRequest($text);

        yield from $this->attachMediaToUpdateRequest($result, $room, ...$files);

        return $result;
    }

    private function buildTwitterRequest(ChatRoom $room, \DOMXPath $xpath)
    {
        return $this->isOnebox($xpath)
            ? yield from $this->buildUpdateRequestFromOnebox($room, $xpath)
            : yield from $this->buildUpdateRequest($room, $xpath);
    }

    public function tweet(Command $command)
    {
        $room = $command->getRoom();

        if (!yield $this->admin->isAdmin($room, $command->getUserId())) {
            return $this->chatClient->postReply($command, "I'm sorry Dave, I'm afraid I can't do that");
        }

        try {
            /** @var TwitterClient $client */
            $client = yield from $this->getClientForRoom($room); // do this first to make sure it's worth going further

            $doc = yield from $this->getRawMessage($room, $command->getParameter(0));
            $xpath = new \DOMXPath($doc);

            $request = yield from $this->buildTwitterRequest($room, $xpath);

            $result = yield $client->request($request);

            $tweetURL = sprintf('https://twitter.com/%s/status/%s', $result['user']['screen_name'], $result['id_str']);

            return $this->chatClient->postMessage($command, $tweetURL);
        } catch (NotConfiguredException $e) {
            return $this->chatClient->postReply($command, "I'm not currently configured for tweeting :-(");
        } catch (LibXMLFatalErrorException $e) {
            return $this->chatClient->postReply($command, 'Totally failed to parse the chat message :-(');
        } catch (MessageIDNotFoundException $e) {
            return $this->chatClient->postReply($command, 'I need a chat message link to tweet');
        } catch (TweetIDNotFoundException $e) {
            return $this->chatClient->postReply($command, "That looks like a retweet but I can't find the tweet ID :-S");
        } catch (UnhandledOneboxException $e) {
            return $this->chatClient->postReply($command, "Sorry, I don't know how to turn that kind of onebox into a tweet");
        } catch (TextProcessingFailedException $e) {
            return $this->chatClient->postReply($command, "Processing the message text failed :-S");
        } catch (MediaProcessingFailedException $e) {
            return $this->chatClient->postReply($command, "Processing the message links and media failed :-S");
        } catch (TweetLengthLimitExceededException $e) {
            return $this->chatClient->postReply($command, "Boo! The message exceeds the 140 character limit. :-(");
        } catch (TwitterRequestFailedException $e) {
            return $this->chatClient->postReply($command, 'Posting to Twitter failed :-( ' . $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'BetterTweet';
    }

    public function getDescription(): string
    {
        return 'Tweets chat messages just like !!tweet only better (WIP)';
    }

    /**
     * @return PluginCommandEndpoint[]
     */
    public function getCommandEndpoints(): array
    {
        return [new PluginCommandEndpoint('BetterTweet', [$this, 'tweet'], 'tweet2')];
    }
}
