<?php
namespace Solid\helpers;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use RocketTheme\Toolbox\File\JsonFile;
use RocketTheme\Toolbox\ResourceLocator\ResourceLocatorInterface;

/**
 * TODO : Utils save / read des fichiers avec le système Grav
 */

class FileIO
{
    /**
     * @var Grav
     */
    public $grav;

    /**
     * @var string
     */
    public $folder;

    /**
     * @var string
     */
    protected $_rootPath;

    /**
     * FileIO constructor.
     * @param Grav $grav Grav instance to be able to use Locator
     * @param string $folder Will be the name of the folder in user/data/$folder/
     */
    public function __construct ($grav, $folder )
    {
        $this->grav = $grav;
        $this->folder = $folder;

        /** @var ResourceLocatorInterface $locator */
        $locator = $grav['locator'];

        // Get folder path and create it if it does not exists yet
        $rootPath = $locator->findResource("user://data/{$this->folder}/", true);
        if (!$rootPath)
        {
            $rootPath = $locator->findResource("user://data/", true).DS.$this->folder;
            mkdir( $rootPath );
        }

        // Add slash and store root path
        $this->_rootPath = $rootPath.DS;
    }

    /**
     * List file within this folder.
     * @return array
     */
    public function list ()
    {
        $allFiles = scandir( $this->_rootPath );
        $filteredFiles = [];
        foreach ($allFiles as $file)
        {
            if ($file == '.' || $file == '..' || pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
            $filteredFiles[] = pathinfo($file, PATHINFO_FILENAME);
        }
        return $filteredFiles;
    }

    /**
     * Target a file within this type.
     * @param string $fileName Filename without folder or extension.
     * @return JsonFile
     */
    public function file ( $fileName )
    {
        return JsonFile::instance( $this->_rootPath.$fileName.'.json' );
    }
}