<?php
namespace Grav\Theme;

use Grav\Common\Markdown\Parsedown;
use Grav\Common\Markdown\ParsedownExtra;
use Grav\Common\Theme;
use Symfony\Component\Yaml\Yaml;

class GravSolid extends Theme
{
	// ------------------------------------------------------------------------- INIT

	public static function getSubscribedEvents ()
	{
		return [
			'onThemeInitialized' => ['onThemeInitialized', 0]
		];
	}

	public function onThemeInitialized ()
	{
		// Admin mode
		if ( $this->isAdmin() )
		{
			// Check if route is valid
			$uri = $this->grav['uri'];
			$route = $this->config->get('plugins.admin.route');
			if ( $route && preg_match('#'.$route.'#', $uri->path()) )
			{
				// Middleware for admin
				$this->enable([
					'onPageInitialized' => ['onPageInitializedAdmin', 0]
				]);
			}

			// Disable regular behavior on admin
			$this->active = false;
		}

		// Regular website mode
		else
		{
			$this->enable([
				'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
			]);
		}
	}


	// ------------------------------------------------------------------------- ADMIN

	public function onPageInitializedAdmin ()
	{
		// Inject admin CSS and JS overrides on admin
		/* @var $assets \Grav\Common\Assets */
		$assets = $this->grav['assets'];
		$assets->addCss('user/themes/grav-solid/admin/custom.css', 1);
		$assets->addJs('user/themes/grav-solid/admin/custom.js', 1);
	}


	// ------------------------------------------------------------------------- FRONT DATA INJECTION

	public function onTwigSiteVariables ()
	{
		$this->injectData();
	}

	protected function injectData ()
	{
		// Get theme parameters
		$themeParams = $this->grav['twig']->twig_vars['theme'];

		// ---- GET PAGES DATA

		// Get current page
		/* @var $currentPage \Grav\Common\Page\Page */
		$currentPage = $this->grav['page'];

		// Get all pages data
		$pagesCollection = $this->grav['pages']->all();

		// Prepare app data with global and pages nodes
		$appData = [
			'globals' => [],
			'pages' => []
		];

		// This is the global folder name.
		// Have to match pages structure, please do not change it.
		$globalFolderName = '/_global/';

		// ---- MARKDOWN & YAML PREPARATION

		// Get markdown config
		/* @var $config \Grav\Common\Config\Config */
		$config = $this->grav['config'];
		$defaults = (array)$config->get('system.pages.markdown');

		// Initialize the preferred variant of Parsedown
		$parsedown = (
			$defaults['extra']
			? new ParsedownExtra($this, $defaults)
			: new Parsedown($this, $defaults)
		);

		// Get markdown and YAML properties to parse from theme options
		$markdownProperties = $themeParams['markdownProperties'];
		$yamlProperties = $themeParams['yamlProperties'];

		// Browse pages to inject
		foreach ($pagesCollection as $page)
		{
			/* @var $page \Grav\Common\Page\Page */

			// ----- CURRENT PAGE INFO

			// Get header and route
			$route = $page->route();

			// Skip global page which is just a folder
			if ($route === $globalFolderName) continue;

			// Get page data through headers
			$pageHeaders = $page->header();

			// Get custom headers if we have some
			$pageData = (
				isset($pageHeaders->custom)
				? $pageHeaders->custom
				: []
			);

			// ----- ENABLED PROCESSING

			// Remove disabled nodes for page data with 'enabled' property.
	 		// And remove "enabled: true" properties to clean it up.
			$this->recursiveEnabled( $pageData );

			// ----- GLOBALS DATA

			// Catch all global pages
			if ( stripos($route, $globalFolderName) === 0 )
			{
				// Register this global page data into app-data globals
				$appData['globals'][ basename($route) ] = $pageData;
				continue;
			}

			// Here we avoid not visible and not inject pages
			// This is not a global page
			else if (
				// If this page is not visible, we always kick it out
				!$page->visible()
				||
				// Never kick current page, even it's not marked as injected
				$currentPage->route() !== $route
				&&
				(
					// Or this page's blueprint is not extending default page
					!isset($pageHeaders->solidify)
					// Or this page ask to be not injected
					|| !$pageHeaders->solidify['injectPageData']
				)

			) continue;

			// ----- MARKDOWN PROCESSING

			// If we have some page data and properties to parse with markdown
			if ( !empty($markdownProperties) && !empty($pageData) )
			{
				// Browser those properties
				foreach ($markdownProperties as $markdownPropertyName)
				{
					// And deeply parse with parsedown
					$this->recursiveParsedown(
						$parsedown,
						$pageData,
						$markdownPropertyName
					);
				}
			}

			// ----- YAML PROCESSING

			// If we have some page data and properties to parse with YAML
			if ( !empty($yamlProperties) && !empty($pageData) )
			{
				// Browser those properties
				foreach ($yamlProperties as $yamlPropertyName)
				{
					// And deeply parse with YAML
					$this->recursiveYaml(
						$pageData,
						$yamlPropertyName
					);
				}
			}

			// ----- PAGE DATA FORMATTING

			// This is a page which wants to be injected.
			// Add page data with route as key
			$appData['pages'][ $route ] = [

				// Inject page title, no need for meta data since this is only for JS
				'title' 	=> (
					isset ($pageHeaders->title)
					? $pageHeaders->title
					: ''
				),

				// Inject solidify page name if specified
				'pageName' 	=> (
					isset($pageHeaders->solidify['pageName'])
					? $pageHeaders->solidify['pageName']
					: null
				),

				// Inject page data
				'data' 		=> $pageData,

				// Inject parsed content only if we have some
				// This avoid to parse empty content
				'content'	=> (
					! empty($page->rawMarkdown())
					? $page->content()
					: ''
				)
			];
		}

		// TODO : Parcourir les pages solidify qui ont des traductions mais qui n'ont jamais été injectée
		// TODO : Si on en trouve, on les vires du global/dico pour éviter de poluer pour rien
		// TODO : Lors de requêtes pour récupérer le JSON d'une page, il faudrait que les trads soient récup
		// TODO : quelle soit injectée ou non car de toutes manières si on tape le JSON c'est que la page n'est pas injectée

		// Debug : show app data
		/*
		echo '<pre>';
		echo json_encode($appData, JSON_PRETTY_PRINT);
		exit;
		//*/

		// ---- INJECT DATA

		// Inject app data
		$this->grav['twig']->twig_vars['appData'] = $appData;
	}


	// ------------------------------------------------------------------------- RECURSIVE PARSING

	/**
	 * Will convert every markdown content recursively.
	 * @param $parsedown Parsedown instance.
	 * @param array $node Node to browse recursively
	 * @param string $pPropName Name of property to convert
	 */
	protected function recursiveParsedown (&$parsedown, &$node, $pPropName)
	{
		// Browse as reference
		foreach ($node as $key => &$value)
		{
			// If key is the prop we want to convert
			if ( $key == $pPropName && is_string($value) )
			{
				// Convert it
				$node[ $pPropName ] = $parsedown->text( $value );
			}

			// Else, browse recursively
			else if (is_array($value))
			{
				$this->recursiveParsedown($parsedown, $value, $pPropName);
			}
		}
	}

	/**
	 * Will convert every YML content recursively.
	 * @param array $node Node to browse recursively
	 * @param string $pPropName Name of property to convert
	 */
	protected function recursiveYaml (&$node, $pPropName)
	{
		// Browse as reference
		foreach ($node as $key => &$value)
		{
			// If key is the prop we want to convert
			if ( $key == $pPropName && is_string($value) )
			{
				// Convert it
				$node[$pPropName] = Yaml::parse( $value );
			}

			// Else, browse recursively
			else if ( is_array($value) )
			{
				$this->recursiveYaml($value, $pPropName);
			}
		}
	}

	/**
	 * Remove disabled nodes for page data with 'enabled' property.
	 * And remove "enabled: true" properties to clean it up.
	 * @param array $node Page data node to clean.
	 * @param string $pEnabledPropName Name of the enabled prop, default is enabled.
	 */
	protected function recursiveEnabled (&$node, $pEnabledPropName = 'enabled')
	{
		// Browse as reference
		foreach ($node as $key => &$value)
		{
			// Only interested with arrays
			if ( !is_array($value) ) continue;

			// If enabled property exists
			if ( isset($value[ $pEnabledPropName ]) )
			{
				// Remove whole node if enabled is false
				if (!$value[ $pEnabledPropName ])
				{
					unset($node[$key]);
					continue;
				}

				// Remove only property if enabled is true
				else
				{
					unset($value[ $pEnabledPropName ]);
				}
			}

			// Continue recursively
			$this->recursiveEnabled( $value );
		}
	}
}