<?php

namespace SilverStripe\Core\Manifest;

use Exception;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Cache\CacheFactory;
use SilverStripe\Dev\TestOnly;

/**
 * A utility class which builds a manifest of all classes, interfaces and caches it.
 *
 * It finds the following information:
 *   - Class and interface names and paths.
 *   - All direct and indirect descendants of a class.
 *   - All implementors of an interface.
 *
 * To be consistent; In general all array keys are lowercase, and array values are correct-case
 */
class ClassManifest
{
    /**
     * base manifest directory
     * @var string
     */
    protected $base;

    /**
     * Used to build cache during boot
     *
     * @var CacheFactory
     */
    protected $cacheFactory;

    /**
     * Cache to use, if caching.
     * Set to null if uncached.
     *
     * @var CacheInterface|null
     */
    protected $cache;

    /**
     * Key to use for the top level cache of all items
     *
     * @var string
     */
    protected $cacheKey;

    /**
     * Array of properties to cache
     *
     * @var array
     */
    protected $serialisedProperties = [
        'classes',
        'classNames',
        'descendants',
        'interfaces',
        'interfaceNames',
        'implementors',
        'traits',
        'traitNames',
    ];

    /**
     * Map of lowercase class names to paths
     *
     * @var array
     */
    protected $classes = array();

    /**
     * Map of lowercase class names to case-correct names
     *
     * @var array
     */
    protected $classNames = [];

    /**
     * List of root classes with no parent class
     * Keys are lowercase, values are correct case.
     *
     * Note: Only used while regenerating cache
     *
     * @var array
     */
    protected $roots = array();

    /**
     * List of direct children for any class.
     * Keys are lowercase, values are arrays.
     * Each item-value array has lowercase keys and correct case for values.
     *
     * Note: Only used while regenerating cache
     *
     * @var array
     */
    protected $children = array();

    /**
     * List of descendents for any class (direct + indirect children)
     * Keys are lowercase, values are arrays.
     * Each item-value array has lowercase keys and correct case for values.
     *
     * @var array
     */
    protected $descendants = array();

    /**
     * Map of lowercase interface name to path those files
     *
     * @var array
     */
    protected $interfaces = [];

    /**
     * Map of lowercase interface name to proper case
     *
     * @var array
     */
    protected $interfaceNames = [];

    /**
     * List of direct implementors of any interface
     * Keys are lowercase, values are arrays.
     * Each item-value array has lowercase keys and correct case for values.
     *
     * @var array
     */
    protected $implementors = array();

    /**
     * Map of lowercase trait names to paths
     *
     * @var array
     */
    protected $traits = [];

    /**
     * Map of lowercase trait names to proper case
     *
     * @var array
     */
    protected $traitNames = [];

    /**
     * PHP Parser for parsing found files
     *
     * @var Parser
     */
    private $parser;

    /**
     * @var NodeTraverser
     */
    private $traverser;

    /**
     * @var ClassManifestVisitor
     */
    private $visitor;

    /**
     * Constructs and initialises a new class manifest, either loading the data
     * from the cache or re-scanning for classes.
     *
     * @param string $base The manifest base path.
     * @param CacheFactory $cacheFactory Optional cache to use. Set to null to not cache.
     */
    public function __construct($base, CacheFactory $cacheFactory = null)
    {
        $this->base = $base;
        $this->cacheFactory = $cacheFactory;
        $this->cacheKey = 'manifest';
    }

    /**
     * Initialise the class manifest
     *
     * @param bool $includeTests
     * @param bool $forceRegen
     */
    public function init($includeTests = false, $forceRegen = false)
    {
        // build cache from factory
        if ($this->cacheFactory) {
            $this->cache = $this->cacheFactory->create(
                CacheInterface::class . '.classmanifest',
                ['namespace' => 'classmanifest' . ($includeTests ? '_tests' : '')]
            );
        }

        // Check if cache is safe to use
        if (!$forceRegen
            && $this->cache
            && ($data = $this->cache->get($this->cacheKey))
            && $this->loadState($data)
        ) {
            return;
        }

        // Build
        $this->regenerate($includeTests);
    }

    /**
     * Get or create active parser
     *
     * @return Parser
     */
    public function getParser()
    {
        if (!$this->parser) {
            $this->parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        }

        return $this->parser;
    }

    /**
     * Get node traverser for parsing class files
     *
     * @return NodeTraverser
     */
    public function getTraverser()
    {
        if (!$this->traverser) {
            $this->traverser = new NodeTraverser;
            $this->traverser->addVisitor(new NameResolver);
            $this->traverser->addVisitor($this->getVisitor());
        }

        return $this->traverser;
    }

    /**
     * Get visitor for parsing class files
     *
     * @return ClassManifestVisitor
     */
    public function getVisitor()
    {
        if (!$this->visitor) {
            $this->visitor = new ClassManifestVisitor;
        }

        return $this->visitor;
    }

    /**
     * Returns the file path to a class or interface if it exists in the
     * manifest.
     *
     * @param  string $name
     * @return string|null
     */
    public function getItemPath($name)
    {
        $lowerName = strtolower($name);
        foreach ([
             $this->classes,
             $this->interfaces,
             $this->traits,
         ] as $source) {
            if (isset($source[$lowerName]) && file_exists($source[$lowerName])) {
                return $source[$lowerName];
            }
        }
        return null;
    }

    /**
     * Return correct case name
     *
     * @param string $name
     * @return string Correct case name
     */
    public function getItemName($name)
    {
        $lowerName = strtolower($name);
        foreach ([
             $this->classNames,
             $this->interfaceNames,
             $this->traitNames,
         ] as $source) {
            if (isset($source[$lowerName])) {
                return $source[$lowerName];
            }
        }
        return null;
    }

    /**
     * Returns a map of lowercased class names to file paths.
     *
     * @return array
     */
    public function getClasses()
    {
        return $this->classes;
    }

    /**
     * Returns a map of lowercase class names to proper class names in the manifest
     *
     * @return array
     */
    public function getClassNames()
    {
        return $this->classNames;
    }

    /**
     * Returns a map of lowercased trait names to file paths.
     *
     * @return array
     */
    public function getTraits()
    {
        return $this->traits;
    }

    /**
     * Returns a map of lowercase trait names to proper trait names in the manifest
     *
     * @return array
     */
    public function getTraitNames()
    {
        return $this->traitNames;
    }

    /**
     * Returns an array of all the descendant data.
     *
     * @return array
     */
    public function getDescendants()
    {
        return $this->descendants;
    }

    /**
     * Returns an array containing all the descendants (direct and indirect)
     * of a class.
     *
     * @param  string|object $class
     * @return array
     */
    public function getDescendantsOf($class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $lClass = strtolower($class);
        if (array_key_exists($lClass, $this->descendants)) {
            return $this->descendants[$lClass];
        }

        return [];
    }

    /**
     * Returns a map of lowercased interface names to file locations.
     *
     * @return array
     */
    public function getInterfaces()
    {
        return $this->interfaces;
    }

    /**
     * Return map of lowercase interface names to proper case names in the manifest
     *
     * @return array
     */
    public function getInterfaceNames()
    {
        return $this->interfaceNames;
    }

    /**
     * Returns a map of lowercased interface names to the classes the implement
     * them.
     *
     * @return array
     */
    public function getImplementors()
    {
        return $this->implementors;
    }

    /**
     * Returns an array containing the class names that implement a certain
     * interface.
     *
     * @param string $interface
     * @return array
     */
    public function getImplementorsOf($interface)
    {
        $lowerInterface = strtolower($interface);
        if (array_key_exists($lowerInterface, $this->implementors)) {
            return $this->implementors[$lowerInterface];
        } else {
            return array();
        }
    }

    /**
     * Get module that owns this class
     *
     * @param string $class Class name
     * @return Module
     */
    public function getOwnerModule($class)
    {
        $path = $this->getItemPath($class);
        return ModuleLoader::inst()->getManifest()->getModuleByPath($path);
    }

    /**
     * Completely regenerates the manifest file.
     *
     * @param bool $includeTests
     */
    public function regenerate($includeTests)
    {
        // Reset the manifest so stale info doesn't cause errors.
        $this->loadState([]);
        $this->roots = [];
        $this->children = [];

        $finder = new ManifestFileFinder();
        $finder->setOptions(array(
            'name_regex' => '/^[^_].*\\.php$/',
            'ignore_files' => array('index.php', 'main.php', 'cli-script.php'),
            'ignore_tests' => !$includeTests,
            'file_callback' => function ($basename, $pathname) use ($includeTests) {
                $this->handleFile($basename, $pathname, $includeTests);
            },
        ));
        $finder->find($this->base);

        foreach ($this->roots as $root) {
            $this->coalesceDescendants($root);
        }

        if ($this->cache) {
            $data = $this->getState();
            $this->cache->set($this->cacheKey, $data);
        }
    }

    /**
     * Visit a file to inspect for classes, interfaces and traits
     *
     * @param string $basename
     * @param string $pathname
     * @param bool $includeTests
     * @throws Exception
     */
    public function handleFile($basename, $pathname, $includeTests)
    {
        // The results of individual file parses are cached, since only a few
        // files will have changed and TokenisedRegularExpression is quite
        // slow. A combination of the file name and file contents hash are used,
        // since just using the datetime lead to problems with upgrading.
        $key = preg_replace('/[^a-zA-Z0-9_]/', '_', $basename) . '_' . md5_file($pathname);

        // Attempt to load from cache
        // Note: $classes, $interfaces and $traits arrays have correct-case keys, not lowercase
        $changed = false;
        if ($this->cache
            && ($data = $this->cache->get($key))
            && $this->validateItemCache($data)
        ) {
            $classes = $data['classes'];
            $interfaces = $data['interfaces'];
            $traits = $data['traits'];
        } else {
            $changed = true;
            // Build from php file parser
            $fileContents = ClassContentRemover::remove_class_content($pathname);
            try {
                $stmts = $this->getParser()->parse($fileContents);
            } catch (Error $e) {
                // if our mangled contents breaks, try again with the proper file contents
                $stmts = $this->getParser()->parse(file_get_contents($pathname));
            }
            $this->getTraverser()->traverse($stmts);

            $classes = $this->getVisitor()->getClasses();
            $interfaces = $this->getVisitor()->getInterfaces();
            $traits = $this->getVisitor()->getTraits();
        }

        // Merge raw class data into global list
        foreach ($classes as $className => $classInfo) {
            $lowerClassName = strtolower($className);
            if (array_key_exists($lowerClassName, $this->classes)) {
                throw new Exception(sprintf(
                    'There are two files containing the "%s" class: "%s" and "%s"',
                    $className,
                    $this->classes[$lowerClassName],
                    $pathname
                ));
            }

            // Skip if implements TestOnly, but doesn't include tests
            $lowerInterfaces = array_map('strtolower', $classInfo['interfaces']);
            if (!$includeTests && in_array(strtolower(TestOnly::class), $lowerInterfaces)) {
                $changed = true;
                unset($classes[$className]);
                continue;
            }

            $this->classes[$lowerClassName] = $pathname;
            $this->classNames[$lowerClassName] = $className;

            // Add to children
            if ($classInfo['extends']) {
                foreach ($classInfo['extends'] as $ancestor) {
                    $lowerAncestor = strtolower($ancestor);
                    if (!isset($this->children[$lowerAncestor])) {
                        $this->children[$lowerAncestor] = [];
                    }
                    $this->children[$lowerAncestor][$lowerClassName] = $className;
                }
            } else {
                $this->roots[$lowerClassName] = $className;
            }

            // Load interfaces
            foreach ($classInfo['interfaces'] as $interface) {
                $lowerInterface = strtolower($interface);
                if (!isset($this->implementors[$lowerInterface])) {
                    $this->implementors[$lowerInterface] = [];
                }
                $this->implementors[$lowerInterface][$lowerClassName] = $className;
            }
        }

        // Merge all found interfaces into list
        foreach ($interfaces as $interfaceName => $interfaceInfo) {
            $lowerInterface = strtolower($interfaceName);
            $this->interfaces[$lowerInterface] = $pathname;
            $this->interfaceNames[$lowerInterface] = $interfaceName;
        }

        // Merge all traits
        foreach ($traits as $traitName => $traitInfo) {
            $lowerTrait = strtolower($traitName);
            $this->traits[$lowerTrait] = $pathname;
            $this->traitNames[$lowerTrait] = $traitName;
        }

        // Save back to cache if configured
        if ($changed && $this->cache) {
            $cache = array(
                'classes' => $classes,
                'interfaces' => $interfaces,
                'traits' => $traits,
            );
            $this->cache->set($key, $cache);
        }
    }

    /**
     * Recursively coalesces direct child information into full descendant
     * information.
     *
     * @param  string $class
     * @return array
     */
    protected function coalesceDescendants($class)
    {
        // Reset descendents to immediate children initially
        $lowerClass = strtolower($class);
        if (empty($this->children[$lowerClass])) {
            return [];
        }

        // Coalasce children into descendent list
        $this->descendants[$lowerClass] = $this->children[$lowerClass];
        foreach ($this->children[$lowerClass] as $childClass) {
            // Merge all nested descendants
            $this->descendants[$lowerClass] = array_merge(
                $this->descendants[$lowerClass],
                $this->coalesceDescendants($childClass)
            );
        }
        return $this->descendants[$lowerClass];
    }

    /**
     * Reload state from given cache data
     *
     * @param array $data
     * @return bool True if cache was valid and successfully loaded
     */
    protected function loadState($data)
    {
        $success = true;
        foreach ($this->serialisedProperties as $property) {
            if (!isset($data[$property]) || !is_array($data[$property])) {
                $success = false;
                $value = [];
            } else {
                $value = $data[$property];
            }
            $this->$property = $value;
        }
        return $success;
    }

    /**
     * Load current state into an array of data
     *
     * @return array
     */
    protected function getState()
    {
        $data = [];
        foreach ($this->serialisedProperties as $property) {
            $data[$property] = $this->$property;
        }
        return $data;
    }

    /**
     * Verify that cached data is valid for a single item
     *
     * @param array $data
     * @return bool
     */
    protected function validateItemCache($data)
    {
        if (!$data || !is_array($data)) {
            return false;
        }
        foreach (['classes', 'interfaces', 'traits'] as $key) {
            // Must be set
            if (!isset($data[$key])) {
                return false;
            }
            // and an array
            if (!is_array($data[$key])) {
                return false;
            }
            // Detect legacy cache keys (non-associative)
            $array = $data[$key];
            if (!empty($array) && is_numeric(key($array))) {
                return false;
            }
        }
        return true;
    }
}