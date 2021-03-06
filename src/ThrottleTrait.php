<?php
namespace Muffin\Throttle;

use Cake\Cache\Cache;
use Cake\Routing\DispatcherFilter;

trait ThrottleTrait
{

    /*
     * Default config for Throttle Middleware
     *
     * @var array
     */
    protected $_throttleConfig = [
        'message' => 'Rate limit exceeded',
        'interval' => '+1 minute',
        'limit' => 10,
        'headers' => [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset'
        ]
    ];

    /**
     * Cache configuration name
     *
     * @var string
     */
    public static $cacheConfig = 'throttle';

    /**
     * Cache expiration key suffix
     *
     * @var string
     */
    public static $cacheExpirationSuffix = 'expires';

    /**
     * Unique client identifier
     *
     * @var string
     */
    protected $_identifier;

    /**
     * Number of connections after increment
     *
     * @var string
     */
    protected $_count;

    /**
     * Function identifier calls
     *
     * @var array
     */
    protected $_compatFunctions = [
        'getConfig' => 'getConfig',
        'setConfig' => 'setConfig',
        'header' => true
    ];

    /**
     * Set the default configuration
     *
     * @return array An array of default configuration
     */
    protected function _setConfiguration()
    {
        $this->_throttleConfig['identifier'] = function ($request) {
            return $request->clientIp();
        };

        if ($this instanceof DispatcherFilter) {
            $this->_compatFunctions = [
                'getConfig' => 'config',
                'setConfig' => 'config',
                'header' => false
            ];
        }

        return $this->_throttleConfig;
    }

    /**
     * Sets the identifier class property. Uses Throttle default IP address
     * based identifier unless a callable alternative is passed.
     *
     * @param \Psr\Http\Message\ServerRequestInterface|\Cake\Http\Request $request RequestInterface instance
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function _setIdentifier($request)
    {
        $key = $this->{$this->_compatFunctions['getConfig']}('identifier');
        if (!is_callable($this->{$this->_compatFunctions['getConfig']}('identifier'))) {
            throw new \InvalidArgumentException('Throttle identifier option must be a callable');
        }
        $this->_identifier = $key($request);
    }

    /**
     * Initializes cache configuration.
     *
     * @return void
     */
    protected function _initCache()
    {
        $getFunction = $this->_compatFunctions['getConfig'];
        $setFunction = $this->_compatFunctions['setConfig'];
        if (!Cache::$getFunction(static::$cacheConfig)) {
            Cache::$setFunction(static::$cacheConfig, [
                'className' => $this->_getDefaultCacheConfigClassName(),
                'prefix' => static::$cacheConfig . '_' . $this->_identifier,
                'duration' => $this->{$this->_compatFunctions['getConfig']}('interval'),
            ]);
        }
    }

    /**
     * Gets the className of the default CacheEngine so the Throttle cache
     * config can use the same. String cast is required to catch a DebugEngine
     * array/object for users with DebugKit enabled.
     *
     * @return string ClassName property of default Cache engine
     */
    protected function _getDefaultCacheConfigClassName()
    {
        $function = $this->_compatFunctions['getConfig'];

        $config = Cache::$function('default');
        $engine = (string)$config['className'];

        // short cache engine names can be returned immediately
        if (strpos($engine, '\\') === false) {
            return $engine;
        }
        // fully namespace cache engine names need extracting class name
        preg_match('/.+\\\\(.+)Engine/', $engine, $matches);

        return $matches[1];
    }

    /**
     * Atomically updates cache using default CakePHP increment offset 1.
     *
     * Please note that the cache key needs to be initialized to prevent
     * increment() failing on 0. A separate cache key is created to store
     * the interval expiration time in epoch.
     *
     * @return mixed
     */
    protected function _touch()
    {
        if (Cache::read($this->_identifier, static::$cacheConfig) === false) {
            Cache::write($this->_identifier, 0, static::$cacheConfig);
            Cache::write($this->_getCacheExpirationKey(), strtotime($this->{$this->_compatFunctions['getConfig']}('interval'), time()), static::$cacheConfig);
        }

        return Cache::increment($this->_identifier, 1, static::$cacheConfig);
    }

    /**
     * Returns cache key holding the epoch cache expiration timestamp.
     *
     * @return string Cache key holding cache expiration in epoch.
     */
    protected function _getCacheExpirationKey()
    {
        return $this->_identifier . '_' . static::$cacheExpirationSuffix;
    }

    /**
     * Extends response with X-headers containing rate limiting information.
     *
     * @param \Psr\Http\Message\ResponseInterface|\Cake\Network\Response $response ResponseInterface instance
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function _setHeaders($response)
    {
        $configFunction = $this->_compatFunctions['getConfig'];
        $header = $this->_compatFunctions['header'];

        $headers = $this->$configFunction('headers');

        if (!is_array($headers)) {
            return $response;
        }

        if ($header) {
            $response = $response->withHeader($headers['limit'], (string)$this->$configFunction('limit'))
                ->withHeader($headers['remaining'], (string)$this->_getRemainingConnections())
                ->withHeader($headers['reset'], (string)Cache::read($this->_getCacheExpirationKey(), static::$cacheConfig));
        } else {
            $response->header($headers['limit'], $this->$configFunction('limit'));
            $response->header($headers['remaining'], $this->_getRemainingConnections());
            $response->header($headers['reset'], Cache::read($this->_getCacheExpirationKey(), static::$cacheConfig));
        }

        return $response;
    }

    /**
     * Calculates the number of hits remaining before client reaches rate limit.
     *
     * @return int Number of remaining client hits, zero if limit is reached
     */
    protected function _getRemainingConnections()
    {
        $remaining = $this->{$this->_compatFunctions['getConfig']}('limit') - $this->_count;
        if ($remaining <= 0) {
            return 0;
        }

        return $remaining;
    }
}
