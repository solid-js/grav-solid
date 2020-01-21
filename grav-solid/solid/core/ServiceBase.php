<?php
namespace Solid\core;

use Grav\Common\Grav;
use Grav\Theme\GravSolid;
use Solid\helpers\HttpIO;

class ServiceBase
{
    /**
     * @var GravSolid
     */
    public $theme;

    /**
     * @var Grav
     */
    public $grav;

    /**
     * GravSolidBaseService constructor injecting dependencies.
     * @param GravSolid $gravSolidTheme
     * @param Grav $grav
     */
    public function __construct ( GravSolid $gravSolidTheme, Grav $grav )
    {
        $this->theme = $gravSolidTheme;
        $this->grav = $grav;
    }
    /**
     * Get parameters sent by client.
     * Will read json through POST for classic rest.
     * Will fallback to classic post forms otherwise.
     * @param mixed $parameters TODO
     * @param mixed $defaultParameters List of field to get and set to empty if not set from post.
     * @param bool $allowGET Allow parameters to be gathered from $_GET
     * @param bool $allowPOST Allow parameters to be gathered from $_POST
     * @return array|mixed
     */
    public static function defaults ( $parameters = [], $defaultParameters = null, $allowGET = true, $allowPOST = true )
    {
        $data = (
            ! empty($parameters)
            ? $parameters
            : HttpIO::request( null, $allowGET, $allowPOST )
        );

        // Set all unset fields
        if ( !is_null($defaultParameters) )
        {
            foreach ( $defaultParameters as $key => $value )
            {
                // Empty string if key is an int and value is the name of the field
                if (is_int($key) && !isset($data[$value]))
                    $data[$value] = '';

                // Set value if key is a string
                else if ( !isset($data[$key]) )
                    $data[$key] = is_null($value) ? '' : $value;
            }
        }

        return $data;
    }

    /**
     * Generate and answer for the client side.
     * Use it by returning the response from within the service action.
     *
     * Example : return $this->response(['code' => 0], 202);
     *
     * Will just return $data if $this->jsonMode is false.
     * Will set headers if $this->jsonMode is true.
     *
     * @param $data mixed object to send back to the client.
     * @param int $httpCode HTTP response code. Default is 200.
     * @return string|array
     * @see $this->jsonMode
     */
    protected function response ( $data, $httpCode = 200 )
    {
        return [$data, $httpCode];
    }
}