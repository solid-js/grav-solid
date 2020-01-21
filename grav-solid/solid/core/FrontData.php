<?php
namespace Solid\core;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Theme\GravSolid;


class FrontData
{
    /**
     * Property name for enabled == false filtering.
     */
    public static $ENABLED_PROPERTY_NAME = 'enabled';

    /**
     * Global folder path.
     * This folder include globally available data such as site metas / dictionaries / configs ...
     */
    public static $GLOBAL_FOLDER_PATH = '/global/';

    /**
     * Common folder path.
     * This folder include data available from certain pages such as common blocks, etc
     */
    public static $COMMON_FOLDER_PATH = '/common/';


    /** @var Grav $__grav */
    protected static $__grav;

    /** @var GravSolid $__theme */
    protected static $__theme;

    /** @var string $__localeToAdd */
    protected static $__localeToAdd;


    /**
     * Initialize front app data manager.
     * @param GravSolid $theme
     */
    static function init ( GravSolid $theme )
    {
        // Target grav and grav solid
        self::$__grav   = $theme->getGrav();
        self::$__theme  = $theme;

        // Get locale to prepend if are in multi locale mode
        if ( count( self::$__theme->getLanguages() ) > 1 )
            self::$__localeToAdd = self::$__theme->getLocale();
    }

    // ------------------------------------------------------------------------- GET GLOBAL AND PAGE DATA

    /**
     * Cached global data
     * @var array
     */
    protected static $__globalData;

    /**
     * Get global data.
     * Will use cache if possible.
     * @param bool $force For an update and disable cache for this call.
     * @param int $depth Depth of data to retrieve, default is -1, which means infinite.
     * @return array
     */
    public static function getGlobalData ( $force = false, $depth = -1 )
    {
        // Get from cache if we do not force fresh data
        if ( !is_null(self::$__globalData) && !$force ) return self::$__globalData;

        // Get global page root
        $globalRoot = self::$__grav['pages']->find( self::$GLOBAL_FOLDER_PATH );

        // Get all pages data from this page
        self::$__globalData = [];
        self::generateRecursivePageData( self::$__globalData, $globalRoot, $depth, true, true );

        // Remove empty global array
        unset(self::$__globalData['global']);

        return self::$__globalData;
    }

    /**
     * Get data for one page.
     * @param Page|null $page Page to retrieve data from.
     * @param int $depth Depth of data to retrieve, default is -1, which means infinite.
     * @return array
     */
    public static function getPageData ( $page, $depth = -1 )
    {
        // Get all pages data from this page
        $pageData = [];
        if (is_null($page))
        {
            return $pageData;
        }
        self::generateRecursivePageData( $pageData, $page, $depth );
        return $pageData;
    }

    // ------------------------------------------------------------------------- RECURSIVE PAGE DATA GENERATION


    /**
     * Generate page data recursively.
     * Will get modules and parse custom properties.
     * @param array $pages Page array as reference which will be fed.
     * @param Page|PageInterface $page Page to gather data from.
     * @param int $depth Depth of data to retrieve, default is -1, which means infinite.
     * @param bool $removeFolderName Remove first folder name, usually for _global pages.
     * @param bool $dataOnRoot Push all page data to the root, usually for _global pages. Note : media will be injected with custom into the root.
     */
    protected static function generateRecursivePageData (Array &$pages, $page, $depth = -1, $removeFolderName = false, $dataOnRoot = false )
    {
        // Get header and route
        $pageRoute = $page->route();

        // Get page data through Grav headers.
        // We do it once by page and memoize it here
        $pageHeaders = $page->header();

        // If this page is visible (default is true)
        $pageVisible = $pageHeaders->visible ?? true;

        // Do not add page if not visible
        if ( !$pageVisible && $pageRoute.'/' !== self::$GLOBAL_FOLDER_PATH ) return;

        // Remove first folder name if needed by arguments (to remove the _global mainly)
        if ( $removeFolderName )
            $key = substr($pageRoute, strpos($pageRoute, '/', 2) + 1, strlen($pageRoute));

        // Prepend routes with locale if we are in multi language mode
        else
            $key = self::patchPageRoute( $pageRoute );

        // Register this global page data into app-data globals
        $pageData = self::processPageData( $page, $pageHeaders );

        // Get children and modular of this page
        if ( count( $page->children() ) != 0 )
        {
            /** @var Page $page */
            foreach ( $page->children() as $child )
            {
                // Modular are included in processPageData
                if ( $child->isModule() && !is_null($pageData) )
                {
                    $pageData['modules'] = $pageData['modules'] ?? [];
                    $pageData['modules'][] = self::processPageData( $child );
                    continue;
                }

                // Recursively get all children for this page
                if ( $depth != 0 )
                    self::generateRecursivePageData( $pages, $child, $depth - 1, $removeFolderName, $dataOnRoot );
            }
        }

        // Do not add folder (which have no data, only children)
        if ( is_null($pageData) || empty($pageData) ) return;

        // For global, we squeeze all meta and push data to the root
        if ( $dataOnRoot )
        {
            // Special case, if we have media we add inject them ;)
            if ( !empty($pageData['media']) )
                $pageData['data']['media'] = $pageData['media'];
            $pages[ $key ] = $pageData['data'];
            return;
        }

        // Add type from template name
        $pageData['type'] = $page->template();

        // Add page data
        $pages[ $key ] = $pageData;
    }

    // ------------------------------------------------------------------------- PAGE DATA PROCESSING

    /**
     * Process one page data.
     * Will convert headers to array and rename custom to data.
     * Will remove useless values.
     * @param PageInterface $page Page to process.
     * @param $pageHeaders Page headers, will retrieve them if not given.
     * @return mixed
     */
    public static function processPageData ( PageInterface $page, $pageHeaders = null )
    {
        // Get page headers if not given as argument
        $pageHeaders = $pageHeaders ?? $page->header();

        // Do not continue to avoid operations on null
        if ( is_null($pageHeaders) ) return null;

        // Convert as array
        $pageData = get_object_vars($pageHeaders);

        // Recursive processing of page data
        self::recursiveProcessCustomData($pageData);

        // Rename custom to data
        if ( isset($pageData['custom']) )
        {
            // Move custom to data
            $pageData['data'] = $pageData['custom'];
            unset( $pageData['custom'] );
        }

        // Clean visible and routable
        unset( $pageData['visible'] );
        unset( $pageData['routable'] );

        return $pageData;
    }

    /**
     * Recursively process and clean custom data.
     * Will rename keys starting with __.
     * Will remove data which contains enabled=false
     * @param Mixed $node Node to clean
     */
    protected static function recursiveProcessCustomData ( &$node )
    {
        $needsArrayCleaning = false;

        // Browse as reference
        foreach ( $node as $key => &$value )
        {
            // Remove source from parsed YAML and Markdown (vars starting with __)
            if ( isset( $node['__'.$key] ) )
            {
                $node[$key] = $node['__'.$key];
                unset( $node['__'.$key] );
                continue;
            }

            // Only interested with arrays
            if ( !is_array($value) ) continue;

            // Clean multi images data
            if ( isset( $value['name'] ) && isset( $value['type'] ) && isset( $value['size'] ) && isset( $value['path'] ) )
            {
                $node = $value['name'];
                break;
                //$node[] = $value['name'];
                //unset( $node[$key] );
                continue;
            }

            // If enabled property exists
            if ( isset( $value[ self::$ENABLED_PROPERTY_NAME ] ) )
            {
                // Remove whole node if enabled is false
                if ( !$value[ self::$ENABLED_PROPERTY_NAME ] )
                {
                    // We need an array cleaning
                    $needsArrayCleaning = true;

                    // Remove whole object and do not go further
                    unset( $node[$key] );
                    continue;
                }

                // Remove only property if enabled is true
                unset( $value[ self::$ENABLED_PROPERTY_NAME ] );
            }

            // Continue recursively
            self::recursiveProcessCustomData( $value );
        }

        // If we removed some disabled elements
        if ( $needsArrayCleaning )
        {
            // Collapse removed indexes
            $valuesToCollapse = $node;
            $node = [];
            foreach ($valuesToCollapse as $valueToClean)
                $node[] = $valueToClean;
        }
    }

    /**
     * Patch page route with locale if needed
     * @param $route
     * @return string
     */
    public static function patchPageRoute ( $route, $withBase = false )
    {
        // Prepend routes keys with locales if we are in multi languages mode
        $routeWithLocale = !is_null(self::$__localeToAdd) ? '/'.self::$__localeToAdd.$route : $route;

        // Remove trailing slash from URL but keep it on home page
        $slashRoute = $routeWithLocale == '/' ? '/' : rtrim($routeWithLocale, '/');

        return (
            $withBase
            ? self::$__theme->getBase() . ltrim($slashRoute, '/')
            : $slashRoute
        );
    }
}