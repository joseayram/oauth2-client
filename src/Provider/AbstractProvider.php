<?php

namespace League\OAuth2\Client\Provider;

use Closure;
use Ivory\HttpAdapter\CurlHttpAdapter;
use Ivory\HttpAdapter\HttpAdapterException;
use Ivory\HttpAdapter\HttpAdapterInterface;
use Ivory\HttpAdapter\Message\RequestInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Grant\GrantFactory;
use League\OAuth2\Client\Token\AccessToken;
use RandomLib\Factory as RandomFactory;
use UnexpectedValueException;

abstract class AbstractProvider implements ProviderInterface
{
    /**
     * @var string Separator used for authorization scopes.
     */
    const SCOPE_SEPARATOR = ',';

    /**
     * @var string Separator used for authorization scopes.
     */
    protected $clientId = '';

    /**
     * @var string
     */
    protected $clientSecret = '';

    /**
     * @var string
     */
    protected $redirectUri = '';

    /**
     * @var string
     */
    protected $state;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $uidKey = 'uid';

    /**
     * @var string
     */
    protected $method = 'post';

    /**
     * @var string
     */
    protected $responseType = 'json';

    /**
     * @var GrantFactory
     */
    protected $grantFactory;

    /**
     * @var HttpAdapterInterface
     */
    protected $httpClient;

    /**
     * @var RandomFactory
     */
    protected $randomFactory;

    /**
     * @var Closure
     */
    protected $redirectHandler;

    /**
     * @var int This represents: PHP_QUERY_RFC1738, which is the default value for php 5.4
     *          and the default encryption type for the http_build_query setup
     */
    protected $httpBuildEncType = 1;

    /**
     * @param array $options
     * @param array $collaborators
     */
    public function __construct($options = [], array $collaborators = [])
    {
        foreach ($options as $option => $value) {
            if (property_exists($this, $option)) {
                $this->{$option} = $value;
            }
        }

        if (empty($collaborators['grantFactory'])) {
            $collaborators['grantFactory'] = new GrantFactory();
        }
        $this->setGrantFactory($collaborators['grantFactory']);

        if (empty($collaborators['httpClient'])) {
            $collaborators['httpClient'] = new CurlHttpAdapter();
        }
        $this->setHttpClient($collaborators['httpClient']);

        if (empty($collaborators['randomFactory'])) {
            $collaborators['randomFactory'] = new RandomFactory();
        }
        $this->setRandomFactory($collaborators['randomFactory']);
    }

    /**
     * Set the grant factory instance.
     *
     * @param  GrantFactory $factory
     * @return $this
     */
    public function setGrantFactory(GrantFactory $factory)
    {
        $this->grantFactory = $factory;

        return $this;
    }

    /**
     * Get the grant factory instance.
     *
     * @return GrantFactory
     */
    public function getGrantFactory()
    {
        return $this->grantFactory;
    }

    /**
     * Set the HTTP adapter instance.
     *
     * @param  HttpAdapterInterface $client
     * @return $this
     */
    public function setHttpClient(HttpAdapterInterface $client)
    {
        $this->httpClient = $client;

        return $this;
    }

    /**
     * Get the HTTP adapter instance.
     *
     * @return HttpAdapterInterface
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }

    /**
     * Set the instance of the CSPRNG random generator factory to use.
     *
     * @param  RandomFactory $factory
     * @return $this
     */
    public function setRandomFactory(RandomFactory $factory)
    {
        $this->randomFactory = $factory;

        return $this;
    }

    /**
     * Get the instance of the CSPRNG random generatory factory.
     *
     * @return RandomFactory
     */
    public function getRandomFactory()
    {
        return $this->randomFactory;
    }

    /**
     * Get the current state of the OAuth flow.
     *
     * This can be accessed by the redirect handler during authorization.
     *
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    // Implementing these interfaces methods should not be required, but not
    // doing so will break HHVM because of https://github.com/facebook/hhvm/issues/5170
    // Once HHVM is working, delete the following abstract methods.
    abstract public function urlAuthorize();
    abstract public function urlAccessToken();
    abstract public function urlUserDetails(AccessToken $token);
    // End of methods to delete.

    /**
     * Get a new random string to use for auth state.
     *
     * @param  integer $length
     * @return string
     */
    protected function getRandomState($length = 32)
    {
        $generator = $this
            ->getRandomFactory()
            ->getMediumStrengthGenerator();

        return $generator->generateString($length);
    }

    /**
     * Get the default scopes used by this provider.
     *
     * This should not be a complete list of all scopes, but the minimum
     * required for the provider user interface!
     *
     * @return array
     */
    abstract protected function getDefaultScopes();

    public function getAuthorizationUrl(array $options = [])
    {
        if (empty($options['state'])) {
            $options['state'] = $this->getRandomState();
        }
        if (empty($options['scope'])) {
            $options['scope'] = $this->getDefaultScopes();
        }

        $options += [
            'response_type'   => 'code',
            'approval_prompt' => 'auto',
        ];

        if (is_array($options['scope'])) {
            $options['scope'] = implode(static::SCOPE_SEPARATOR, $options['scope']);
        }

        // Store the state, it may need to be accessed later.
        $this->state = $options['state'];

        $params = [
            'client_id'       => $this->clientId,
            'redirect_uri'    => $this->redirectUri,
            'state'           => $this->state,
            'scope'           => $options['scope'],
            'response_type'   => $options['response_type'],
            'approval_prompt' => $options['approval_prompt'],
        ];

        return $this->urlAuthorize().'?'.$this->httpBuildQuery($params, '', '&');
    }

    // @codeCoverageIgnoreStart
    public function authorize(array $options = [])
    {
        $url = $this->getAuthorizationUrl($options);
        if ($this->redirectHandler) {
            $handler = $this->redirectHandler;
            return $handler($url, $this);
        }
        // @codeCoverageIgnoreStart
        header('Location: ' . $url);
        exit;
        // @codeCoverageIgnoreEnd
    }

    public function getAccessToken($grant = 'authorization_code', array $params = [])
    {
        if (is_string($grant)) {
            $grant = $this->grantFactory->getGrant($grant);
        } else {
            $this->grantFactory->checkGrant($grant);
        }

        $defaultParams = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => (string) $grant,
        ];

        $requestParams = $grant->prepRequestParams($defaultParams, $params);

        try {
            $client = $this->getHttpClient();
            switch (strtoupper($this->method)) {
                case 'GET':
                    // @codeCoverageIgnoreStart
                    // No providers included with this library use get but 3rd parties may
                    $httpResponse = $client->get(
                        $this->urlAccessToken(),
                        $this->getHeaders(),
                        $requestParams
                    );
                    $response = (string) $httpResponse->getBody();
                    break;
                    // @codeCoverageIgnoreEnd
                case 'POST':
                    $httpResponse = $client->post(
                        $this->urlAccessToken(),
                        $this->getHeaders(),
                        $requestParams
                    );
                    $response = (string) $httpResponse->getBody();
                    break;
                // @codeCoverageIgnoreStart
                default:
                    throw new \InvalidArgumentException('Neither GET nor POST is specified for request');
                // @codeCoverageIgnoreEnd
            }
        } catch (HttpAdapterException $e) {
            $response = (string) $e->getResponse()->getBody();
        }

        $response = $this->parseResponse($response);
        $response = $this->prepareAccessTokenResult($response);

        return $grant->handleResponse($response);
    }

    /**
     * Get an authenticated request instance.
     *
     * Creates a PSR-7 compatible request instance that can be modified.
     * Often used to create calls against an API that requires authentication.
     *
     * @param  string $method
     * @param  string $url
     * @param  AccessToken $token
     * @return RequestInterface
     */
    public function getAuthenticatedRequest($method, $url, AccessToken $token)
    {
        $factory = $this->getHttpClient()
            ->getConfiguration()
            ->getMessageFactory();

        $request = $factory->createRequest($url, $method);
        $request->addHeaders($this->getHeaders($token));
        return $request;
    }

    /**
     * Get a response for a request instance.
     *
     * Processes the response according to provider response type.
     *
     * @param  RequestInterface $request
     * @return mixed
     */
    public function getResponse(RequestInterface $request)
    {
        try {
            $client = $this->getHttpClient();

            $httpResponse = $client->sendRequest($request);

            $response = (string) $httpResponse->getBody();
        } catch (HttpAdapterException $e) {
            $response = (string) $e->getResponse()->getBody();
        }

        $response = $this->parseResponse($response);

        return $response;
    }

    /**
     * Parse the response, according to the provider response type.
     *
     * @throws UnexpectedValueException
     * @param  string $response
     * @return array
     */
    protected function parseResponse($response)
    {
        $result = [];

        switch ($this->responseType) {
            case 'json':
                $result = json_decode($response, true);
                if (JSON_ERROR_NONE !== json_last_error()) {
                    throw new UnexpectedValueException('Unable to parse client response');
                }
                break;
            case 'string':
                parse_str($response, $result);
                break;
        }

        $this->checkResponse($result);

        return $result;
    }

    /**
     * Check a provider response for errors.
     *
     * @throws IdentityProviderException
     * @param  array $response
     * @return void
     */
    abstract protected function checkResponse(array $response);

    /**
     * Prepare the access token response for the grant. Custom mapping of
     * expirations, etc should be done here.
     *
     * @param  array $result
     * @return array
     */
    protected function prepareAccessTokenResult(array $result)
    {
        $this->setResultUid($result);
        return $result;
    }

    /**
     * Sets any result keys we've received matching our provider-defined uidKey to the key "uid".
     *
     * @param array $result
     */
    protected function setResultUid(array &$result)
    {
        // If we're operating with the default uidKey there's nothing to do.
        if ($this->uidKey === "uid") {
            return;
        }

        if (isset($result[$this->uidKey])) {
            // The AccessToken expects a "uid" to have the key "uid".
            $result['uid'] = $result[$this->uidKey];
        }
    }

    /**
     * Generate a user object from a successful user details request.
     *
     * @param object $response
     * @param AccessToken $token
     * @return League\OAuth2\Client\Provider\UserInterface
     */
    abstract protected function prepareUserDetails(array $response, AccessToken $token);

    public function getUserDetails(AccessToken $token)
    {
        $response = $this->fetchUserDetails($token);

        return $this->prepareUserDetails($response, $token);
    }

    /**
     * Build HTTP the HTTP query, handling PHP version control options
     *
     * @param  array        $params
     * @param  integer      $numeric_prefix
     * @param  string       $arg_separator
     * @param  null|integer $enc_type
     *
     * @return string
     * @codeCoverageIgnoreStart
     */
    protected function httpBuildQuery($params, $numeric_prefix = 0, $arg_separator = '&', $enc_type = null)
    {
        if (version_compare(PHP_VERSION, '5.4.0', '>=') && !defined('HHVM_VERSION')) {
            if ($enc_type === null) {
                $enc_type = $this->httpBuildEncType;
            }
            $url = http_build_query($params, $numeric_prefix, $arg_separator, $enc_type);
        } else {
            $url = http_build_query($params, $numeric_prefix, $arg_separator);
        }

        return $url;
    }

    protected function fetchUserDetails(AccessToken $token)
    {
        $url = $this->urlUserDetails($token);

        $request = $this->getAuthenticatedRequest(RequestInterface::METHOD_GET, $url, $token);

        return $this->getResponse($request);
    }

    /**
     * Get additional headers used by this provider.
     *
     * Typically this is used to set Accept or Content-Type headers.
     *
     * @param  AccessToken $token
     * @return array
     */
    protected function getDefaultHeaders($token = null)
    {
        return [];
    }

    /**
     * Get authorization headers used by this provider.
     *
     * Typically this is "Bearer" or "MAC". For more information see:
     * http://tools.ietf.org/html/rfc6749#section-7.1
     *
     * No default is provided, providers must overload this method to activate
     * authorization headers.
     *
     * @return array
     */
    protected function getAuthorizationHeaders($token = null)
    {
        return [];
    }

    /**
     * Get the headers used by this provider for a request.
     *
     * If a token is passed, the request may be authenticated through headers.
     *
     * @param  mixed $token  object or string
     * @return array
     */
    public function getHeaders($token = null)
    {
        $headers = $this->getDefaultHeaders();
        if ($token) {
            $headers = array_merge($headers, $this->getAuthorizationHeaders($token));
        }
        return $headers;
    }

    public function setRedirectHandler(Closure $handler)
    {
        $this->redirectHandler = $handler;
    }
}
