<?php
namespace Grav\Theme;

use Grav\Common\Assets;
use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Theme;
use Grav\Common\Twig\Twig;
use Solid\core\FrontData;
use Solid\core\AdminData;
use Solid\core\ServicesManager;
use RocketTheme\Toolbox\Event\Event;
use Solid\helpers\HttpIO;


class GravSolid extends Theme
{
    /**
     * Get Grav instance
     * @return Grav
     */
    public function getGrav () { return $this->grav; }

    /**
     * Current Grav page
     * @var Page
     */
    protected $_page;
    public function getPage () { return $this->_page; }

    // Current page data
    protected $_pageData;
    public function getPageData () { return $this->_pageData; }

    /**
     * Grav assets manager
     * @var Assets
     */
    protected $_assets;
    public function getAssets () { return $this->_assets; }

    // Theme config
    protected $_themeConfig;
    public function getThemeConfig () { return $this->_themeConfig; }

    // Solid config is the generated yaml file to keep track of version and env properties
    protected $_solidConfig;
    public function getSolidConfig () { return $this->_solidConfig; }

    // Injected app data
    protected $_appData;
    public function getAppData () { return $this->_appData; }

    // Patched base for multi-languages websites
    protected $_base;
    public function getBase () { return $this->_base; }

    // Patched scheme with reverse proxy concerns
    protected $_scheme;
    public function getScheme () { return $this->_scheme; }

    // Patched absolute base for multi-languages websites
    protected $_absoluteBase;
    public function getAbsoluteBase ( $withScheme = false )
    {
        return ($withScheme ? $this->_scheme.':' : '').$this->_absoluteBase;
    }

    // All language codes
    protected $_languages;
    public function getLanguages () { return $this->_languages; }

    // All current locale
    protected $_locale;
    public function getLocale () { return $this->_locale; }

    // If page has not been found
    protected $_notFound = false;
    public function getNotFound () { return $this->_notFound; }

    // Current requested path
    protected $_path;
    public function getPath ( $withLocale = true )
    {
        return ($withLocale ? '/'.$this->_locale : '').$this->_path;
    }


    // ------------------------------------------------------------------------- INIT

	public static function getSubscribedEvents ()
	{
		return [
		    'onThemeInitialized'    => ['onThemeInitialized', 0],
            'onBlueprintCreated'    => ['onBlueprintCreated', 1]
        ];
	}

	public function onThemeInitialized ()
	{
        // Prepare needed configs
        $this->prepareConfigs();

        // Front-end and not admin panel
		if ( $this->isAdmin() )
		{
			// Check if route is valid
			$uri = $this->grav['uri'];
			$route = $this->config->get('plugins.admin.route');
			if ( $route && preg_match('#'.$route.'#', $uri->path()) )
			{
				// Middleware for admin
				$this->enable([
					'onPageInitialized' => ['onPageInitializedAdmin', 0],
                    'onAdminSave'       => ['onAdminSave', 0],
                    'onAdminAfterSave'  => ['onAdminAfterSave', 0],
                    'onAdminPageTypes' => ['onAdminPageTypes', 0],
                    'onAdminModularPageTypes' => ['onAdminModularPageTypes', 0],
                ]);
			}

			// Disable regular behavior on admin
			$this->active = false;
		}

		// Front-end mode
		else
		{
			$this->enable([
                'onPagesInitialized'    => ['onPagesInitialized', 10],
                'onPageInitialized'     => ['onPageInitializedFrontEnd', 0],
                'onTwigSiteVariables'   => ['onTwigSiteVariables', 0],
                'onPageNotFound'        => ['onPageNotFound', 0],
			]);
		}
	}

    // ------------------------------------------------------------------------- PREPARE CONFIGS

    protected function prepareConfigs ()
    {
        // Get assets manager
        $this->_assets = $this->grav['assets'];

        // Get theme config and store it
        $this->_themeConfig = $this->config->get('theme');

        // Prepare solid config generated from deployer
        $this->_solidConfig = $this->config->get('solid') ?? [];
        if (!isset($this->_solidConfig['version'])) $this->_solidConfig['version'] = '0.0';
        $this->config->set('solid', $this->_solidConfig);

        // Get all languages list and current locale
        $this->_languages = $this->grav['language']->getLanguages();
        $this->_locale = $this->grav['language']->getActive() ?? $this->grav['language']->getDefault();

        // Problem  : base_url in theme, includes language. We do not want language
        //            appended to be able to target files and assets.
        // Solution : base_url here (in this very function) oddly seems to not include language,
        //            so we include 'base' theme var as a clone of base_url now.
        $this->_base = rtrim($this->grav['base_url'], '/').'/';

        // Get absolute base and remove scheme because in some server configs,
        // https is returning http
        $this->_absoluteBase = rtrim($this->grav['base_url_absolute']).'/';
        $this->_absoluteBase = substr($this->_absoluteBase, stripos($this->_absoluteBase, '//'), strlen($this->_absoluteBase));

        // Get correct scheme, even if we are behind a reverse proxy
        // Note     : If this is returning http even in https with a reverse proxy,
        //            add these lines to nginx config :
        //              location / {
        //                  proxy_set_header Host $http_host;
        //                  proxy_set_header X-Real-IP $remote_addr;
        //                  proxy_set_header X-Forwarded-Proto $scheme;
        //                  [...]
        //              }
        $this->_scheme = (
            (
                isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
            )
            ? 'https' : 'http'
        );
    }

    // ------------------------------------------------------------------------- BACK-END BOOTSTRAP

	public function onPageInitializedAdmin ()
	{
		// Inject admin CSS and JS overrides on admin
		$this->_assets->addCss('theme://solid/admin/custom.css', 1);
		$this->_assets->addJs('theme://solid/admin/custom.js', 1);

		// Inject next files only when editing pages
        $page = $this->grav['admin']->page(true);
        if (!$page) return;

        // Add type-selector plugin
        $this->_assets->addCss('theme://solid/admin/type-selector.css', 1);
        $this->_assets->addJs('theme://solid/admin/type-selector.js', 1);
	}

    public function onAdminSave ( Event $event )
    {
        // Get saved object
        $obj = $event->toArray();
        $savedObject = $obj['object'];

        // Process page data if saved object is a page
        if ( $savedObject instanceof Page )
        {
            AdminData::init( $this );
            AdminData::processPageData( $savedObject, true );
        }
    }

    public function onAdminAfterSave ( Event $event )
    {
        // Get saved object
        $obj = $event->toArray();
        $savedObject = $obj['object'];

        // Saving any data
        if ( $savedObject instanceof Data )
        {
            // Get file saved
            $blueprints = $savedObject->blueprints();
            $fileName = $blueprints->getFilename();

            // Detect if we are saving theme options
            if ( $fileName == pathinfo(__FILE__, PATHINFO_FILENAME).'/blueprints' )
            {
                //dump($savedObject->toArray());exit;

                // Update just saved theme config
                $this->_themeConfig = $savedObject->toArray();

                // Init admin data manager
                AdminData::init( $this );

                // Browse and process all pages with new theme settings
                // IMPORTANT : This can be very long and crash
                $pages = $this->grav['pages']->all();
                foreach ( $pages as $page )
                    AdminData::processPageData( $page, false );
            }
        }
    }

    /**
     * Remove some templates types from admin.
     * @see https://github.com/getgrav/grav-plugin-admin/pull/1105
     * @param $event
     */
    public function onAdminPageTypes ( $event )
    {
        $types = $event['types'];

        // Remove defaults
        unset($types['default']);
        //unset($types['default-page']);
        unset($types['default-global']);

        // Remove singletons
        unset($types['global-site']);
        unset($types['global-meta']);
        unset($types['common-uploads']);

        // FIXME
        unset($types['external']);
        unset($types['form']);
        $event['types'] = $types;
    }

    /**
     * Remove some modular templates
     * @param $event
     */
    public function onAdminModularPageTypes ( $event )
    {
        // FIXME : noop
    }

    // ------------------------------------------------------------------------- FRONT-END BOOTSTRAP

    public function onPagesInitialized ()
    {
        // We need front end data generator for json and twig renderings
        FrontData::init( $this );

        // ---- SITEMAP

        // Remove global and common pages from sitemap
        $route = $this->config->get('plugins.sitemap.route');
        if ( $route && $route == $this->grav['uri']->path() )
        {
            $globalPages = $this->grav['pages']->routes();
            $pagesToIgnore = [];
            foreach ($globalPages as $route => $url)
            {
                if (
                    strpos($route.'/', FrontData::$GLOBAL_FOLDER_PATH) === 0
                    ||
                    strpos($route.'/', FrontData::$COMMON_FOLDER_PATH) === 0
                )
                    $pagesToIgnore[] = $route;
            }

            $this->config->set('plugins.sitemap.ignores', array_merge(
                $this->config->get('plugins.sitemap.ignores'),
                $pagesToIgnore
            ));
        }
    }

    public function onPageInitializedFrontEnd ()
    {
        // Get URL paths
        $paths = $this->grav['uri']->paths();

        // ---- SERVICE API

        // Get api endpoint from theme config
        $apiEndpoint = $this->_themeConfig['apiEndpoint'] ?? '';
        if ( !empty($apiEndpoint) && isset($paths[0]) )
        {
            // Verify if we are on API URL, without locale
            $isOnAPI = false;
            if ( isset( $paths[0] ) && strtolower( $paths[0] ) == $apiEndpoint )
                $isOnAPI = true;

            // In this case we are on API URL but with a locale defined
            else if ($paths[0] == $this->_locale && isset( $paths[1] ) && strtolower( $paths[1] ) == $apiEndpoint )
            {
                // Remove locale from paths
                $isOnAPI = true;
                array_shift( $paths );
            }

            // Remove api endpoint
            array_shift( $paths );

            if ( $isOnAPI )
            {
                // Try to exec service
                ServicesManager::init( $this );
                ServicesManager::execForHTTP( $paths );
                exit;
            }
        }

        // ---- PAGE REQUEST ( JSON or HTML )

        // Requested path, with trailing slash
        $this->_path = $this->grav['uri']->path();
        $this->_path = rtrim( $this->_path, '/' ).'/';

        // Get page for this requested path
        $this->_page = $this->grav['pages']->find( $this->_path );

        // ---- JSON PAGE REQUEST

        // Check if only json is asked for rendering
        if ( strtolower($this->grav['uri']->extension()) == 'json' )
        {
            // Return 404 code if page not found
            if ( is_null($this->_page) ) HttpIO::sendNotFoundHeader( true );

            // Generate only current page app data
            $pageData = FrontData::getPageData( $this->_page );

            // Return only first generated page data
            foreach ( $pageData as $onlyPageData )
            {
                // Return generated page app-data as JSON
                print HttpIO::response( $onlyPageData );
                exit;
            }
        }

        // ---- HEADLESS KILL SWITCH

        // Do not continue with headless mode.
        // Because we only serve jsons and no twig templates (see above)
        $mode = $this->_themeConfig['mode'];
        if ( $mode == 'headless' )
            HttpIO::sendNotFoundHeader( true, '<h1>Page not found</h1>' );

        // ---- COMMON & GLOBAL

        // Do not allow HTML data rendering of global and common
        if (
            stripos(strtolower($this->_path), FrontData::$GLOBAL_FOLDER_PATH) === 0
            ||
            stripos(strtolower($this->_path), FrontData::$COMMON_FOLDER_PATH) === 0
        )
        {
            $this->_notFound = true;
            $this->_page = null;
        }

        // ---- HTML PAGE REQUEST

        // Get this page data
        $currentPageData = FrontData::getPageData( $this->_page );
        foreach ($currentPageData as $data) $this->_pageData = $data;

        // Generate app data for twig rendering
        $this->_appData = [
            'global' => FrontData::getGlobalData(),
            'pages' => $currentPageData,
            'found' => !$this->_notFound,
            'server' => [
                'scheme' => $this->_scheme,
                'path' => $this->_path,
                'base' => $this->_base,
                'absoluteBase' => $this->_absoluteBase
            ],
            'locales' => [
                'current' => $this->_locale,
                'available' => $this->_languages,
            ],
            'solid' => $this->_solidConfig
        ];

        // Browse pages to inject from theme options
        if ( isset($this->_themeConfig['injectPages']) && is_array($this->_themeConfig['injectPages']) )
        {
            foreach ( $this->_themeConfig['injectPages'] as $injectedPage )
            {
                // Target page object and quit if not found
                $pageObjectToInject = $this->grav['pages']->find( $injectedPage['page'] );
                if (is_null($pageObjectToInject)) continue;

                // Get page data and inject them into app data
                $this->_appData['pages'] += FrontData::getPageData( $pageObjectToInject, intval($injectedPage['depth'], 10) ?? '0');
            }
        }

        /**   DEBUG APP DATA   **/
        //dump($this->_appData);exit;
    }

    public function onPageNotFound ( Event $event )
    {
        // In twig mode, we get the not found page from theme config
        if ( $this->_themeConfig['mode'] == 'twig' )
        {
            $event->page = $this->grav['pages']->find(
                $this->_themeConfig['notFoundPage']
            );
        }

        // Only for solid mode
        // Every pages, even not founds, are solid pages
        else if ( $this->_themeConfig['mode'] == 'solid' )
            $event->page = $this->grav['pages']->find('/');

        // Show not found content
        if (is_null($event->page))
            HttpIO::sendNotFoundHeader( true, '<h1>Page not found</h1>');

        // Set page on event so this will not throw an exception
        $event->stopPropagation();
    }

    // ------------------------------------------------------------------------- FRONT-END TWIG INJECTION

    public function onTwigSiteVariables ()
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];

        // Do not continue to manage twig for solid
        // A plugin may have already something to show
        if (!is_null($twig->template)) return;

        // Inject env data
        $twig->twig_vars['base']            = $this->_base;
        $twig->twig_vars['absoluteBase']    = $this->_absoluteBase;
        $twig->twig_vars['scheme']          = $this->_scheme;
        $twig->twig_vars['locale']          = $this->_locale;

        // Inject data for front end
        $twig->twig_vars['appData']         = $this->_appData;
        $twig->twig_vars['global']          = $this->_appData['global'];
        $twig->twig_vars['pageData']        = $this->_pageData;

        // ---- META

        // Create meta bag with default values
        $meta = [
            'title'         => $twig->twig_vars['site']['title'] ?? '',
            'description'   => $twig->twig_vars['site']['description'] ?? '',
            'canonical' => (
                isset($this->_page)
                ? $this->getAbsoluteBase( true ).ltrim($this->_page->url(false, true, true), '/')
                : ''
            ),
            'share' => []
        ];

        // This holds the share image media object (from page or global meta)
        $shareMedia = [];

        // Inject global site meta if defined
        if ( isset($this->_appData['global']['site-meta']) )
        {
            // Target global share meta
            $shareMeta = $this->_appData['global']['site-meta'];

            // Set title meta from global meta
            if ( isset($shareMeta['title']) )
                $meta['title'] = $shareMeta['title'];

            // Set description from global meta
            if ( isset($shareMeta['description']) )
                $meta['description'] = $shareMeta['description'];

            // Set share meta from global meta
            if ( isset($shareMeta['share']) )
            {
                $meta['share'] = $shareMeta['share'];
                $shareMedia = $shareMeta['media'] ?? [];
            }
        }

        // IF we have a current page
        if ( isset($this->_page) && !is_null($this->_page) )
        {
            // Set title meta from page
            $meta['title'] = $this->_page->title();

            // Set description meta from page
            if ( isset($this->_pageData['description']) )
                $meta['description'] = $this->_pageData['description'];

            // Set share meta from page meta
            if ( isset($this->_pageData['share']) )
            {
                // Merge every props (+= not working here since no override)
                $meta['share'] = array_merge($meta['share'], $this->_pageData['share']);
                $shareMedia = $this->_pageData['media'] ?? [];
            }
        }

        // If we have a share image
        if ( isset($meta['share']['image']) )
        {
            // Failsafe, remove image info if we do not find link
            // This should never happens
            if ( !isset($shareMedia[ $meta['share']['image'] ]) )
                unset($meta['share']['image']);

            else
            {
                // Target image info from media object
                $shareImage = $shareMedia[ $meta['share']['image'] ];

                // Set share image link
                $imageHref = (
                    // From share named size
                    isset($shareImage['sizes']) && isset($shareImage['sizes']['share'])
                    ? $shareImage['sizes']['share']
                    // Or with default
                    : $shareImage['url']
                );

                // Share URL are always absolute
                $meta['share']['image'] = $this->getAbsoluteBase( true ).$imageHref;
            }
        }

        // Facebook og:site_name is meta description by default
        if ( !isset($meta['share']['facebook']) )
            $meta['share']['facebook'] = $meta['description'];

        // Inject
        $twig->twig_vars['meta'] = $meta;

        // ---- DATA AS HTML

        // Render data as HTML
        if ( $this->_themeConfig['dataAsHTML'] == '1' && !is_null($this->_pageData) )
        {
            // Inject page title
            $htmlBuffer = '<h1>'.htmlspecialchars($this->_pageData['title']).'</h1>';

            // Inject menus
            foreach ( $this->_appData['global'] as $globalKey => $globalItem )
            {
                if ( stripos($globalKey, '-menu') === false || !isset($globalItem['entries']) ) continue;

                $htmlBuffer .= '<ul>';
                foreach ( $globalItem['entries'] as $menuItem )
                    $htmlBuffer .= $this->generateLinkHTML( $menuItem, 'li' );
                $htmlBuffer .= '</ul>';
            }

            // Inject page content recursively
            $htmlBuffer .= $this->recursiveGenerateHTML( $this->_pageData['data'], $this->_pageData );
            $twig->twig_vars['dataAsHTML'] = $htmlBuffer;
        }

        // ---- PAGE TEMPLATE

        // Get page template if possible
        $pageTemplate = (
            ! is_null( $this->_page )
            ? $this->_page->template()
            : 'none'
        );

        // TODO : Be able to select custom templates in special cases
        // TODO : Example if we want to create just a share URL with meta

        // Only in solid mode
        // Force default html to be loaded for every pages, even not found
        if ( $this->_themeConfig['mode'] == 'solid' )
            $twig->template = 'default.html.twig';

        // ---- SCRIPTS

        // TODO : Async scripts
        // TODO : Absolute href for CSS and JS ?
        // TODO : JS Inject ( like : `Application.start( __appData );` )

        // Resources to inject in template (scripts and styles)
        $resourcesToInject = [
            'scripts' => [],
            'styles' => []
        ];

        // If we have scripts to inject from theme config
        if ( is_array($this->_themeConfig['scripts']) )
        {
            // Browse all resources to inject
            foreach ($this->_themeConfig['scripts'] as $resource)
            {
                // If this resource have a template filter
                if ( !empty($resource['templates']) && !in_array($pageTemplate, $resource['templates']) ) continue;

                // Add script if needed
                if (!empty($resource['script']))
                    $resourcesToInject['scripts'][] = $resource['script'];

                // Add style if needed
                if (!empty($resource['style']))
                    $resourcesToInject['styles'][] = $resource['style'];
            }
        }

        // Give resources list to twig template
        $twig->twig_vars['resources'] = $resourcesToInject;
    }

    /**
     * Generate page data as HTML, recursively.
     * This is very roughly so do not expect perfect results from this method.
     *
     * @param Mixed $node Current node or page data "data" node to start parsing.
     * @param Mixed $pageData Page data (not recursive) so this method can get media object
     * @param int $currentTitleLevel Current level of title tag. Default is h1, every title will increment this value recursively.
     * @return string Generated HTML
     */
    protected function recursiveGenerateHTML ($node, $pageData, $currentTitleLevel = 1)
    {
        $html = '';
        foreach ( $node as $key => $value )
        {
            // Recursively generate html
            if ( is_array($value) )
            {
                // Do not recursively parse Yaml Object, for 2 reasons :
                // 1. We do not know nature of data and so which kind of tag to create
                // 2. Raw HTML can be injected which can lead to serious security issues
                $isAYamlObject = false;
                foreach ( $this->_themeConfig['yamlProperties'] as $property )
                {
                    if ( $key == $property )
                    {
                        $isAYamlObject = true;
                        break;
                    }
                }
                if ($isAYamlObject) continue;

                // Detect links
                if (isset($value['type']) && (isset($value['page']) || isset($value['href'])))
                {
                    $html .= $this->generateLinkHTML( $value );
                    continue;
                }

                // Parse recursively
                $html .= $this->recursiveGenerateHTML( $value, $pageData, $currentTitleLevel );
            }

            // Detect titles
            else if ( stripos($key, 'title') !== false )
            {
                // Create H tag
                $currentTitleLevel ++;
                $html .= "<h$currentTitleLevel>".htmlspecialchars($value)."</h$currentTitleLevel>";
            }

            // Detect images
            else if ( isset($pageData['media'][$value]) )
            {
                // Target image meta
                $media = $pageData['media'][$value];
                if ( !isset($media['type']) ) continue;

                // Only manage images
                if ( $media['type'] != 'image' ) continue;

                // If several images
                if (isset($media['sizes']) && !empty($media['sizes']))
                {
                    // Get first image size
                    $firstSizeKey = array_key_first($media['sizes']);
                    if ( is_null($firstSizeKey) ) continue;

                    // Generate image tag
                    $src = $media['sizes'][$firstSizeKey];
                }

                // Otherwise get uploaded and non optimized image
                else $src = $media['url'];
                $html .= "<img src='$this->_base$src' />";
            }

            // Else detect string based elements
            else if ( is_string($value) )
            {
                // Detect if this is a markdown value
                $isMarkdownObject = false;
                foreach ($this->_themeConfig['markdownProperties'] as $property)
                {
                    if ( $key == $property )
                    {
                        $isMarkdownObject = true;
                        break;
                    }
                }

                // Inject raw parsed markdown
                if ( $isMarkdownObject )
                    $html .= $isMarkdownObject;

                else
                {
                    // TODO : Only insert strings with spaces ? What's the best todo here ?
                    //$html .= "<p>".htmlspecialchars($value)."</p>"
                }
            }
        }
        return $html;
    }

    /**
     * Generate link HTML
     * @param $linkObject
     * @param null $surroundedTag
     * @return string
     */
    protected function generateLinkHTML ( $linkObject, $surroundedTag = null )
    {
        // Generate link to a page
        if ($linkObject['type'] == 'page')
        {
            $page = $this->grav['pages']->find( $linkObject['page'] );
            if (is_null($page)) return '';
            $href = FrontData::patchPageRoute( $page->route(), true );
        }

        // Free hyperlink
        else if ($linkObject['type'] == 'external')
        {
            $href = $linkObject['href'];

            // Add rel=nofollow for external links
            $rel = "nofollow";

            // Add target if specified
            if (isset($linkObject['target']))
                $target = $linkObject['target'];
        }
        else return '';

        // Add surrounded tag
        $output = '';
        if (!is_null($surroundedTag)) $output .= "<$surroundedTag>";

        // Base link with href
        $output .= "<a href=\"$href\"";

        // Rel and target
        if (isset($rel))        $output .= " rel=\"$rel\"";
        if (isset($target))     $output .= " target=\"$target\"";

        // Link content
        $output .= ">".$linkObject['name']."</a>";

        // Closing
        if ( !is_null($surroundedTag) ) $output .= "</$surroundedTag>";
        return $output;
    }


    // -------------------------------------------------------------------------

    /**
     * Prevent sitemap to extends global and common blueprints
     * @param Event $event
     */
    public function onBlueprintCreated (Event $event)
    {
        /** @var Blueprint $blueprint */
        $blueprint = $event['blueprint'];

        (
            stripos($blueprint->getFilename(), 'global') !== false
            ||
            stripos($blueprint->getFilename(), 'common') !== false
        )
        && $event->stopPropagation();
    }
}