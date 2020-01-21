<?php
namespace Solid\core;

use Grav\Common\Grav;
use Grav\Theme\GravSolid;
use Solid\helpers\HttpIO;

class ServicesManager
{
    /**  @var Grav $__grav */
    protected static $__grav;

    /**  @var GravSolid $__theme */
    protected static $__theme;

    /* List of instantiated services */
    protected static $__services = [];

    /**
     * Initialize front app data manager.
     * @param GravSolid $theme
     */
    static function init ( GravSolid $theme )
    {
        // Target grav and grav solid
        self::$__grav   = $theme->getGrav();
        self::$__theme  = $theme;
    }

    /**
     * Call a service.
     * @param string $serviceName Service name is the name of the service php file to call
     * @param string $actionName Action to call is the method on this service file
     * @param array $parameters Parameters to give to the method.
     * @return mixed First element will be response object, second element is the response code (default is 200)
     * @throws ServiceException Can throw ServiceException if service is not found.
     */
    public static function call ( string $serviceName, string $actionName, $parameters = [] )
    {
        // Check if this service exists
        $servicePath = realpath(__DIR__ . '/../../services/' . $serviceName . '.php');
        if ( !$servicePath )
            throw new ServiceException("Unable to found service $serviceName", 404);

        // Require service file and create it
        require_once $servicePath;
        $serviceClassName = ucfirst( $serviceName . 'Service' );

        // Store service instance in cache / instantiate it
        $serviceInstance = (
            isset( self::$__services[$serviceName] )
            ? self::$__services[$serviceName]
            : new $serviceClassName( self::$__theme, self::$__grav )
        );
        self::$__services[ $serviceName ] = $serviceInstance;

        // Check if this action exists as a method on the service instance
        if ( !method_exists( $serviceInstance, $actionName ) )
            throw new ServiceException("Action $actionName not found on service $serviceClassName", 404);

        // Call it and give it arguments from paths
        return $serviceInstance->$actionName( $parameters );
    }


    /**
     * Execute a service for HTTP.
     * Will get parameters from HttpIO::request and answer as JSON.
     * @param string[] $paths List of paths, without locale
     * @return mixed|null
     */
    public static function execForHTTP ( $paths = [] )
    {
        // Never cache services
        HttpIO::sendNoCacheHeaders();

        // If there is no service name or action name
        if ( !isset( $paths[0] ) || !isset( $paths[1] ) )
            HttpIO::sendNotFoundHeader( true );

        // Try to call service
        try
        {
            // Call service and add all paths as request parameters
            $response = self::call( $paths[0], $paths[1], HttpIO::request($paths) );

            // Print response to the browser
            print HttpIO::response( $response[0], $response[1] );
        }

        // Detect if service is not found
        catch ( ServiceException $e )
        {
            if ( $e->getCode() == 404 ) HttpIO::sendNotFoundHeader( true );
        }
    }
}