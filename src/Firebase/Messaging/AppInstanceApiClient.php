<?php

declare(strict_types=1);

namespace Kreait\Firebase\Messaging;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\MessagingApiExceptionConverter;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Util\JSON;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @internal
 */
class AppInstanceApiClient
{
    /** @var ClientInterface */
    private $client;

    /** @var MessagingApiExceptionConverter */
    private $errorHandler;

    /**
     * @internal
     */
    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
        $this->errorHandler = new MessagingApiExceptionConverter();
    }

    /**
     * @param array<Topic> $topics
     *
     * @return array<string, RegistrationTokens|MessagingException>
     */
    public function subscribeToTopics(array $topics, RegistrationTokens $tokens): array
    {
        $promises = [];

        foreach ($topics as $topic) {
            /** @var Topic $topic */
            $topicName = $topic->value();

            $promises[$topicName] = $this->client->requestAsync('POST', '/iid/v1:batchAdd', [
                'json' => [
                    'to' => '/topics/'.$topicName,
                    'registration_tokens' => $tokens->asStrings(),
                ],
            ])->then(static function (ResponseInterface $response) {
                return JSON::decode((string) $response->getBody(), true);
            });
        }

        $responses = Promise\Utils::settle($promises)->wait();

        $result = [];

        foreach ($responses as $topicName => $response) {
            $topicName = (string) $topicName;

            switch ($response['state']) {
                case 'fulfilled':
                    $result[$topicName] = $tokens;
                    break;
                case 'rejected':
                    $result[$topicName] = $this->errorHandler->convertException($response['value']);
                    break;
            }
        }

        return $result;
    }

    /**
     * @param array<Topic> $topics
     *
     * @return array<string, RegistrationTokens|MessagingException>
     */
    public function unsubscribeFromTopics(array $topics, RegistrationTokens $tokens): array
    {
        $promises = [];

        foreach ($topics as $topic) {
            /** @var Topic $topic */
            $topicName = $topic->value();

            $promises[$topicName] = $this->client->requestAsync('POST', '/iid/v1:batchRemove', [
                'json' => [
                    'to' => '/topics/'.$topicName,
                    'registration_tokens' => $tokens->asStrings(),
                ],
            ]);
        }

        $responses = Promise\Utils::settle($promises)->wait();

        $result = [];

        foreach ($responses as $topicName => $response) {
            $topicName = (string) $topicName;

            switch ($response['state']) {
                case 'fulfilled':
                    $result[$topicName] = $tokens;
                    break;
                case 'rejected':
                    $result[$topicName] = $this->errorHandler->convertException($response['value']);
                    break;
            }
        }

        return $result;
    }

    /**
     * @throws FirebaseException
     * @throws MessagingException
     */
    public function getAppInstance(string $registrationToken): ResponseInterface
    {
        return $this->requestApi('GET', '/iid/'.$registrationToken.'?details=true');
    }

    public function getAppInstanceAsync(RegistrationToken $registrationToken): Promise\PromiseInterface
    {
        return $this->client->requestAsync('GET', '/iid/'.$registrationToken->value().'?details=true')
            ->then(static function (ResponseInterface $response) use ($registrationToken) {
                $data = JSON::decode((string) $response->getBody(), true);

                return AppInstance::fromRawData($registrationToken, $data);
            })
            ->otherwise(function (Throwable $e) {
                return Promise\Create::rejectionFor($this->errorHandler->convertException($e));
            });
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws FirebaseException
     * @throws MessagingException
     */
    private function requestApi(string $method, string $endpoint, ?array $options = null): ResponseInterface
    {
        try {
            return $this->client->request($method, $endpoint, $options ?? []);
        } catch (Throwable $e) {
            throw $this->errorHandler->convertException($e);
        }
    }
}
