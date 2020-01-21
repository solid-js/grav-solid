<?php
namespace Solid\core;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Markdown\Parsedown;
use Grav\Common\Markdown\ParsedownExtra;
use Grav\Common\Page\Medium\ImageMedium;
use Grav\Common\Page\Medium\Medium;
use Grav\Common\Page\Page;
use Grav\Theme\GravSolid;
use Symfony\Component\Yaml\Yaml;

class AdminData
{
    /** @var Grav $__grav */
    protected static $__grav;

    /** @var GravSolid $__theme */
    protected static $__theme;

    /** @var Parsedown $__parseDown */
    protected static $__parseDown;

    /**
     * Initialize admin app data manager.
     * @param GravSolid $theme
     * @throws \Exception
     */
    static function init ( GravSolid $theme )
    {
        // Target grav and grav solid
        self::$__grav   = $theme->getGrav();
        self::$__theme  = $theme;

        // Get markdown config
        /* @var Config $config */
        $config = self::$__grav['config'];
        $defaults = (array) $config->get('system.pages.markdown');

        // Initialize the preferred variant of Parsedown
        self::$__parseDown = (
            $defaults['extra']
            ? new ParsedownExtra(null, $defaults)
            : new Parsedown(null, $defaults)
        );
    }

    /**
     * Process page data and update page headers before save.
     * Will convert markdown to HTML and YAML to Arrays.
     * Will store upload images fields following theme preferences.
     * Will resize images and generate cache following theme preferences.
     * @param Page $page Page to process
     * @param bool $modify Modify current page headers or set new headers and save if false.
     */
    static function processPageData ( Page $page, $modify = true )
    {
        // Target custom headers
        $pageHeaders = $page->header();
        $customHeaders = $pageHeaders->custom ?? [];

        // Name of template name of this page
        $templateName = $page->template();

        // Get markdown and YAML properties to parse from theme options
        $themeConfig = self::$__theme->getThemeConfig();
        $markdownProperties = $themeConfig['markdownProperties'];
        $yamlProperties = $themeConfig['yamlProperties'];

        // ----- MARKDOWN AND YAML PROCESSING

        // If we have some page data and properties to parse with markdown
        if ( !empty($markdownProperties) && !empty($customHeaders) )
        {
            // Browser those properties
            foreach ($markdownProperties as $markdownPropertyName)
            {
                // And deeply parse with parsedown
                self::recursiveParsedown( self::$__parseDown, $customHeaders, $markdownPropertyName );
            }
        }

        // If we have some page data and properties to parse with YAML
        if ( !empty($yamlProperties) && !empty($customHeaders) )
        {
            // Browser those properties
            foreach ($yamlProperties as $yamlPropertyName)
            {
                // And deeply parse with YAML
                self::recursiveYaml( $customHeaders, $yamlPropertyName );
            }
        }

        // ----- UPLOADS PROCESSING

        // Target upload properties to inject from theme options
        $uploadPropertiesToInject = $themeConfig['uploads']['properties'] ?? [];
        $imagesSizes = $themeConfig['images']['sizes'] ?? [];

        // Getting all media of the page and inject them
        $pageMediaList = [];
        $allPageMedia = $page->media()->all();

        /* @var Medium $fileMedium */
        foreach ( $allPageMedia as $mediaFileName => $fileMedium )
        {
            // Get file meta and type
            $meta = $fileMedium->meta();
            $type = $meta->get('type');

            // Create an array for all files properties to inject
            $fileProperties = [
                // URL will be removed if this will be a resized image
                // Remove base and leading slash
                'url' => self::removeBaseAndQuery( $fileMedium->url(false) )
            ];

            // Inject file type (image / video / etc ...)
            if ($uploadPropertiesToInject['type'])
                $fileProperties['type'] = $type;

            // Inject modified timestamp date
            if ($uploadPropertiesToInject['modified'])
                $fileProperties['modified'] = $meta->get('modified');

            // Inject file size
            if ($uploadPropertiesToInject['filesize'])
                $fileProperties['filesize'] = $meta->get('size');

            // Add those file properties to this page's files list
            $pageMediaList[ $mediaFileName ] = $fileProperties;

            // Do not continue if this is not an image
            if ( $type !== 'image' ) continue;
            /* @var ImageMedium $fileMedium */

            // Inject width and height
            if ( $uploadPropertiesToInject['size'] )
            {
                $pageMediaList[ $mediaFileName ]['width'] = $meta->get('width');
                $pageMediaList[ $mediaFileName ]['height'] = $meta->get('height');
            }

            // Inject ratio
            if ( $uploadPropertiesToInject['ratio'] )
                $pageMediaList[ $mediaFileName ]['ratio'] = $meta->get('width') / $meta->get('height');

            // Cache uploaded image and patch URL
            $pageMediaList[ $mediaFileName ]['url'] = self::getCleanMediumLink( $fileMedium );

            // Do not continue if this image is not resized
            if ( empty($imagesSizes) ) continue;

            // Browser every sizes to create
            $createdImages = [];
            foreach ( $imagesSizes as $key => $size )
            {
                // If this image size is only for some pages or modules
                // And if this page has a solid name
                if ( isset($size['onlyFor']) && !empty($size['onlyFor']) && !empty($templateName) )
                {
                    // Check if this page or module is listed into this size
                    $found = false;
                    foreach ( $size['onlyFor'] as $onlyFor )
                    {
                        if ( strtolower($onlyFor) != strtolower($templateName) ) continue;
                        $found = true;
                        break;
                    }

                    // Skip this image version for this module or page if it's filtered out
                    if (!$found) continue;
                }

                // Resize it
                $fileMedium->__call('resize', [
                    $size['width']      ?? null,
                    $size['height']     ?? null,
                    $size['background'] ?? 'transparent',
                    $size['force']      ?? false,
                    $size['rescale']    ?? false,
                    $size['crop']       ?? false
                ]);

                // Enable greyscale version
                if ( isset($size['grayscale']) )   $fileMedium->__call('grayscale', []);

                // Smooth factor
                if ( isset($size['smooth']) )      $fileMedium->__call('smooth', [ $size['smooth'] ] );

                // Colorized version
                if ( isset($size['colorize']) )
                {
                    $p = explode(',', $size['colorize']);
                    $fileMedium->__call('colorize', [ $p[0], $p[1], $p[2] ] );
                }

                // Compress and cache this file and keep image type if possible.
                $fileMedium->__call('cacheFile', [ 'guess', $size['quality'] ?? 80 ] );

                // Get image public path and remove base to clean app data
                $path = self::getCleanMediumLink( $fileMedium );

                // Add this file path to generated images list
                $name = $size['name'] ?? $key;
                $createdImages[ $name ] = $path;
            }

            // Inject created sizes array into file properties
            $pageMediaList[ $mediaFileName ]['sizes'] = $createdImages;
        }

        // Modify page headers to inject media array and custom headers
        if ( $modify )
        {
            // Page will be saved later
            $page->modifyHeader('custom', $customHeaders);
            $page->modifyHeader('media', $pageMediaList);
        }
        else
        {
            // Add headers if they exists
            if ( !empty($customHeaders) )   $pageHeaders->custom = $customHeaders;
            if ( !empty($pageMediaList) )   $pageHeaders->media = $pageMediaList;

            // Set and save this page
            $page->header( $pageHeaders );
            $page->save();
        }
    }

    /**
     * @param ImageMedium $fileMedium
     * @return string
     */
    protected static function getCleanMediumLink ( $fileMedium )
    {
        return self::removeBaseAndQuery( $fileMedium->link( true )->parsedownElement()['attributes']['href'] );
    }

    // ------------------------------------------------------------------------- RECURSIVE PARSING

    /**
     * Will convert every markdown content to HTML recursively.
     * @param $parsedown Parsedown instance.
     * @param array $node Node to browse recursively
     * @param string $propertyName Name of property to convert
     */
    protected static function recursiveParsedown (&$parsedown, &$node, $propertyName)
    {
        // Browse as reference
        foreach ( $node as $key => &$value )
        {
            // If key is the prop we want to convert
            if ( $key == $propertyName && is_string($value) )
            {
                // Convert it
                $node[ '__'.$propertyName ] = $parsedown->text( $value );
            }

            // Else, browse recursively
            else if ( is_array($value) )
            {
                self::recursiveParsedown($parsedown, $value, $propertyName);
            }
        }
    }

    /**
     * Will convert every YML content to object recursively.
     * @param array $node Node to browse recursively
     * @param string $propertyName Name of property to convert
     */
    protected static function recursiveYaml (&$node, $propertyName)
    {
        // Browse as reference
        foreach ( $node as $key => &$value )
        {
            // If key is the prop we want to convert
            if ( $key === $propertyName && is_string($value) )
            {
                // Convert it
                $node[ '__' . $propertyName ] = Yaml::parse( $value, 64 );
            }

            // Else, browse recursively
            else if ( is_array($value) )
            {
                self::recursiveYaml($value, $propertyName);
            }
        }
    }

    // ------------------------------------------------------------------------- PATH TOOLS

    /**
     * Remove base and query from any path.
     * Will also remove leading slash.
     * @param $path
     * @return string
     */
    protected static function removeBaseAndQuery ( $path )
    {
        $path = substr( $path, strlen(self::$__theme->getBase()) );
        $queryPos = strpos($path, '?');
        $path = substr( $path, 0, $queryPos === false ? strlen($path) : $queryPos );
        return ltrim($path, '/');
    }
}