<?php

/**
 * This class is a Rest service example for Grav Solid Theme.
 *
 * To create your own service, create a new file named accordingly to what the service is about.
 * For example, we want to create a service "Contact"
 *
 * Create a new php file : user/themes/grav-solid/services/contact.php
 *
 * Lowercase, no dashes or underscores. Avoid multiple word names.
 *
 * Inside this php file, create a new class named from your service, for ex : ContactService
 * It needs to extends ServiceBase.
 *
 * Then create actions as public functions. Any public function will be callable from URL.
 * For example, if you create a public function called sendMessage, this URL will call this action :
 *
 * /api/contact/sendMessage
 */

use Solid\core\FrontData;
use Solid\core\ServiceBase;
use Solid\core\ServiceException;
use Solid\core\ServicesManager;
use Solid\helpers\FileIO;


/**
 * Name your class from your name.
 * This service will be callable from :
 * /api/example/{action}
 */
class ExampleService extends ServiceBase
{
    /**
     * This action is publicly available as :
     * /api/example/test
     *
     * @param array $parameters TODO
     * @return array|string
     * @throws ServiceException
     */
    public function test ( $parameters )
    {
        // Default parameters to avoid isset AND empty checks
        $parameters = $this->defaults( $parameters, [
            'param1' => 'default value',
            'total' => 0
        ]);

        // You can access to Grav like so
        $gravConfig = $this->grav['config'];

        // You can access to theme vars like so
        $base = $this->theme->getBase();

        // Get global data
        $globalData = FrontData::getGlobalData();

        // Get app data of home page
        $homePage = $this->grav['pages']->find('/');
        $homeData = FrontData::getPageData( $homePage );

        // Service actions can be called from other services
        /*
        $sendMessageResponse = ServicesManager::call('test', 'sendMessage', [
            'email' => 'test@example.com',
            'message' => 'This is a test message'
        ]);

        // Returned data are in $sendMessageResponse[0]
        // Returned code is in $sendMessageResponse[1]
        */

        // Return data
        return $this->response([
            'parameters' => $parameters,
            'global' => $globalData,
            'home' => $homeData
        ]);
    }
    /**
     * This action is publicly available as :
     * @param $parameters
     * @return array|string
     */
    public function sendMessage ( $parameters )
    {
        // Default parameters to avoid isset AND empty checks
        $parameters = $this->defaults( $parameters, ['email', 'message'] );

        // Honeypot : Check if name has been filled to detect bots
        if ( !empty($parameters['name']) )
            return $this->response( ['sent' => 1] );

        // Check missing parameters
        if ( empty($parameters['email']) || empty($parameters['message']) )
            return $this->response( ['error' => 'missing parameters'], 400 );

        // Get configs to send e-mail
        $emailPluginConfig = $this->grav['config']->get('plugins.email');
        $destinationEmail = $this->grav['config']->get('site.author.email');

        // Generate e-mail body and headers
        $message = $this->grav['Email']->message(
            'New message !',
            implode("\n\r", [
                "Incoming message from ".$parameters['email'],
                '---',
                $parameters['message']
            ]),
            'text/plain'
        )
            // Configure it
            ->setReplyTo( $parameters['email'] )
            ->setFrom( $emailPluginConfig['from'], $emailPluginConfig['from_name'] )
            ->setTo( $destinationEmail );

        // Send
        $sent = $this->grav['Email']->send( $message );

        // Respond send result
        return $this->response(['sent' => $sent]);
    }

    /**
     * TODO : DOC
     * @param $parameters
     * @return array|string
     */
    public function save ( $parameters )
    {
        // Default parameters to avoid isset AND empty checks
        $parameters = $this->defaults( $parameters, ['param'] );

        // Init a file bucket named test
        // All files will be stored in user/data/test/
        $testBucket = new FileIO($this->grav, 'test');

        // Create a response bag
        $response = [
            'files' => []
        ];

        // List all files and add them to the response bag
        $list = $testBucket->list();
        foreach ( $list as $name )
        {
            // Target this file
            $file = $testBucket->file( $name );

            // Delete files which are more than 60 seconds old
            if ( time() - $file->modified() > 60 )
                $file->delete();

            // Add this file to the bucket
            else
            {
                $response['files'][] = [
                    'name' => $name,
                    'modified' => $file->modified(),
                    'content' => $file->content()
                ];
            }
        }

        // If we have a parameter as GET or POST
        if ( !empty($parameters['param']) )
        {
            // Create a new file with current timestamp as file name
            $newFile = $testBucket->file( time() );

            // Set content and save it
            $newFile->content([
                'date' => date('ymd'),
                'param' => $parameters['param']
            ]);
            $newFile->save();
        }

        // Show response
        return $this->response( $response );
    }
}
