<?php

/*
 * This file is part of the Symfony CMF package.
 *
 * (c) Symfony CMF
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Cmf\Bundle\CoreBundle\Templating\Helper;

use Doctrine\ODM\PHPCR\DocumentManager;
use Doctrine\ODM\PHPCR\Translation\MissingTranslationException;
use Doctrine\Persistence\ManagerRegistry;
use PHPCR\Util\PathHelper;
use Symfony\Cmf\Bundle\CoreBundle\PublishWorkflow\PublishWorkflowChecker;
use Symfony\Cmf\Component\Routing\RouteReferrersReadInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Layout helper for the CMF.
 *
 * This class is used by the symfony templating integration and by the twig extension.
 *
 * @author Wouter J <waldio.webdesign@gmail.com>
 */
class Cmf
{
    /**
     * @var ManagerRegistry
     */
    private $doctrineRegistry;

    /**
     * @var string
     */
    private $doctrineManagerName;

    /**
     * @var DocumentManager
     */
    protected $dm;

    /**
     * @var AuthorizationCheckerInterface
     */
    private $publishWorkflowChecker;

    /**
     * @param AuthorizationCheckerInterface $publishWorkflowChecker
     */
    public function __construct(AuthorizationCheckerInterface $publishWorkflowChecker = null)
    {
        $this->publishWorkflowChecker = $publishWorkflowChecker;
    }

    /**
     * Set the doctrine manager registry to fetch the object manager from.
     *
     * @param string|null $managerName Manager name if not the default
     */
    public function setDoctrineRegistry(ManagerRegistry $registry, ?string $managerName = null)
    {
        if ($this->doctrineRegistry) {
            throw new \LogicException('Do not call this setter repeatedly.');
        }

        $this->doctrineRegistry = $registry;
        $this->doctrineManagerName = $managerName;
    }

    protected function getDm(): DocumentManager
    {
        if (!$this->dm) {
            if (!$this->doctrineRegistry) {
                throw new \RuntimeException('Doctrine is not available.');
            }

            $this->dm = $this->doctrineRegistry->getManager($this->doctrineManagerName);
        }

        return $this->dm;
    }

    /**
     * @param object $document
     *
     * @return bool|string node name or false if the document is not in the unit of work
     */
    public function getNodeName($document)
    {
        $path = $this->getPath($document);
        if (false === $path) {
            return false;
        }

        return PathHelper::getNodeName($path);
    }

    /**
     * @param object $document
     *
     * @return bool|string node name or false if the document is not in the unit of work
     */
    public function getParentPath($document)
    {
        $path = $this->getPath($document);
        if (!$path) {
            return false;
        }

        return PathHelper::getParentPath($path);
    }

    /**
     * @param object $document
     *
     * @return bool|string path or false if the document is not in the unit of work
     */
    public function getPath($document)
    {
        try {
            return $this->getDm()->getUnitOfWork()->getDocumentId($document);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Finds a document by path.
     *
     * @param $path
     *
     * @return object|null
     */
    public function find($path)
    {
        return $this->getDm()->find(null, $path);
    }

    /**
     * Finds a document by path and locale.
     *
     * @param string|object $pathOrDocument the identifier of the class (path or document object)
     * @param string        $locale         the language to try to load
     * @param bool          $fallback       set to true if the language fallback mechanism should be used
     *
     * @return object|null
     */
    public function findTranslation($pathOrDocument, string $locale, bool $fallback = true)
    {
        if (\is_object($pathOrDocument)) {
            $path = $this->getDm()->getUnitOfWork()->getDocumentId($pathOrDocument);
        } else {
            $path = $pathOrDocument;
        }

        return $this->getDm()->findTranslation(null, $path, $locale, $fallback);
    }

    /**
     * Gets a document instance and validate if its eligible.
     *
     * @param string|object $document   the id of a document or the document
     *                                  object itself
     * @param bool|null     $ignoreRole whether the bypass role should be
     *                                  ignored (leading to only show published content regardless of the
     *                                  current user) or null to skip the published check completely
     * @param string|null   $class      class name to filter on
     *
     * @return object|null
     */
    private function getDocument($document, ?bool $ignoreRole = false, ?string $class = null)
    {
        if (\is_string($document)) {
            try {
                $document = $this->getDm()->find(null, $document);
            } catch (MissingTranslationException $e) {
                return;
            }
        }

        if (null !== $ignoreRole && null === $this->publishWorkflowChecker) {
            throw new InvalidConfigurationException('You can not fetch only published documents when the publishWorkflowChecker is not set. Either enable the publish workflow or pass "ignoreRole = null" to skip publication checks.');
        }

        if (empty($document)
            || (false === $ignoreRole && !$this->publishWorkflowChecker->isGranted(PublishWorkflowChecker::VIEW_ATTRIBUTE, $document))
            || (true === $ignoreRole && !$this->publishWorkflowChecker->isGranted(PublishWorkflowChecker::VIEW_ANONYMOUS_ATTRIBUTE, $document))
            || (null !== $class && !($document instanceof $class))
        ) {
            return;
        }

        return $document;
    }

    /**
     * @param array       $paths      list of paths
     * @param int|bool    $limit      int limit or false
     * @param string|bool $offset     string node name to which to skip to or false
     * @param bool|null   $ignoreRole if the role should be ignored or null if publish workflow should be ignored
     * @param string|null $class      class name to filter on
     *
     * @return array
     */
    public function findMany(array $paths = [], $limit = false, $offset = false, ?bool $ignoreRole = false, ?string $class = null)
    {
        if ($offset) {
            $paths = \array_slice($paths, $offset);
        }

        $result = [];
        foreach ($paths as $path) {
            $document = $this->getDocument($path, $ignoreRole, $class);
            if (null === $document) {
                continue;
            }

            $result[] = $document;
            if (false !== $limit) {
                --$limit;
                if (!$limit) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Check if a document is published, regardless of the current users role.
     *
     * If you need the bypass role, you will have a firewall configured and can
     * simply use {{ is_granted('VIEW', document) }}
     *
     * @param object $document
     */
    public function isPublished($document): bool
    {
        if (null === $this->publishWorkflowChecker) {
            throw new InvalidConfigurationException('You can not check for publication as the publish workflow is not enabled.');
        }

        if (empty($document)) {
            return false;
        }

        return $this->publishWorkflowChecker->isGranted(PublishWorkflowChecker::VIEW_ANONYMOUS_ATTRIBUTE, $document);
    }

    /**
     * Get the locales of the document.
     *
     * @param string|object $document Document instance or path
     *
     * @return string[]
     */
    public function getLocalesFor($document, bool $includeFallbacks = false): array
    {
        if (\is_string($document)) {
            $document = $this->getDm()->find(null, $document);
        }

        if (empty($document)) {
            return [];
        }

        try {
            $locales = $this->getDm()->getLocalesFor($document, $includeFallbacks);
        } catch (MissingTranslationException $e) {
            $locales = [];
        }

        return $locales;
    }

    /**
     * @param string|object $parent parent path/document
     *
     * @return bool|object|null child or null if the child cannot be found
     *                          or false if the parent is not managed by
     *                          the configured document manager
     */
    public function getChild($parent, string $name)
    {
        if (\is_object($parent)) {
            try {
                $parent = $this->getDm()->getUnitOfWork()->getDocumentId($parent);
            } catch (\Exception $e) {
                return false;
            }
        }

        return $this->getDm()->find(null, "$parent/$name");
    }

    /**
     * Gets child documents.
     *
     * @param string|object $parent     parent id or document
     * @param int|bool      $limit      maximum number of children to get or
     *                                  false for no limit
     * @param string|bool   $offset     node name to which to skip to or false
     * @param string|null   $filter     child name filter (optional)
     * @param bool|null     $ignoreRole whether the role should be ignored or
     *                                  null if publish workflow should be
     *                                  ignored (defaults to false)
     * @param string|null   $class      class name to filter on (optional)
     *
     * @return array
     */
    public function getChildren($parent, $limit = false, $offset = false, $filter = null, $ignoreRole = false, $class = null)
    {
        if (empty($parent)) {
            return [];
        }

        if (\is_object($parent)) {
            $parent = $this->getDm()->getUnitOfWork()->getDocumentId($parent);
        }
        $node = $this->getDm()->getPhpcrSession()->getNode($parent);
        $children = (array) $node->getNodeNames();
        foreach ($children as $key => $child) {
            // filter before fetching data already to save some traffic
            if (0 === strpos($child, 'phpcr_locale:')) {
                unset($children[$key]);

                continue;
            }
            $children[$key] = "$parent/$child";
        }
        if ($offset) {
            $key = array_search($offset, $children);
            if (false === $key) {
                return [];
            }
            $children = \array_slice($children, $key);
        }

        $result = [];
        foreach ($children as $name => $child) {
            // if we requested all children above, we did not filter yet
            if (0 === strpos($name, 'phpcr_locale:')) {
                continue;
            }

            // $child is already a document, but this method also checks access
            $child = $this->getDocument($child, $ignoreRole, $class);
            if (null === $child) {
                continue;
            }

            $result[] = $child;
            if (false !== $limit) {
                --$limit;
                if (!$limit) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Gets linkable child documents of a document or repository id.
     *
     * This has the same semantics as the isLinkable method.
     *
     * @param string|object $parent     parent path/document
     * @param int|bool      $limit      limit or false for no limit
     * @param string|bool   $offset     node name to which to skip to or false
     *                                  to not skip any elements
     * @param string|null   $filter     child name filter
     * @param bool|null     $ignoreRole whether the role should be ignored or
     *                                  null if publish workflow should be
     *                                  ignored (defaults to false)
     * @param string|null   $class      class name to filter on
     *
     * @see isLinkable
     */
    public function getLinkableChildren($parent, $limit = false, $offset = false, ?string $filter = null, ?bool $ignoreRole = false, ?string $class = null): array
    {
        $children = $this->getChildren($parent, $limit, $offset, $filter, $ignoreRole, $class);
        foreach ($children as $key => $value) {
            if (!$this->isLinkable($value)) {
                unset($children[$key]);
            }
        }

        return $children;
    }

    /**
     * Check whether a document can be linked to, meaning the path() function
     * should be usable.
     *
     * A document is linkable if it is either instance of
     * Symfony\Component\Routing\Route or implements the
     * RouteReferrersReadInterface and actually returns at least one route in
     * getRoutes.
     *
     * This does not work for route names or other things some routers may
     * support, only for objects.
     *
     * @param object $document
     *
     * @return bool true if it is possible to generate a link to $document
     */
    public function isLinkable($document): bool
    {
        return
            $document instanceof Route
            || ($document instanceof RouteReferrersReadInterface
                && \count($document->getRoutes()) > 0
            )
        ;
    }

    /**
     * Gets the paths of children, updating the $children parameter.
     *
     * @param string[] $children
     * @param ?int     $depth
     */
    private function getChildrenPaths(string $path, array &$children, ?int $depth)
    {
        if (null !== $depth && $depth < 1) {
            return;
        }

        --$depth;

        $node = $this->getDm()->getPhpcrSession()->getNode($path);
        $names = (array) $node->getNodeNames();
        foreach ($names as $name) {
            if (0 === strpos($name, 'phpcr_locale:')) {
                continue;
            }

            $children[] = $child = "$path/$name";
            $this->getChildrenPaths($child, $children, $depth);
        }
    }

    /**
     * @param string|object $parent parent path/document
     * @param int|null      $depth  null denotes no limit, depth of 1 means
     *                              direct children only
     *
     * @return string[]
     */
    public function getDescendants($parent, ?int $depth = null): array
    {
        if (empty($parent)) {
            return [];
        }

        $children = [];
        if (\is_object($parent)) {
            $parent = $this->getDm()->getUnitOfWork()->getDocumentId($parent);
        }
        $this->getChildrenPaths($parent, $children, $depth);

        return $children;
    }

    /**
     * Check children for a possible following document.
     *
     * @param bool $ignoreRole
     *
     * @return object|null
     */
    private function checkChildren(array $childNames, string $path, ?bool $ignoreRole = false, ?string $class = null)
    {
        foreach ($childNames as $name) {
            if (0 === strpos($name, 'phpcr_locale:')) {
                continue;
            }

            $child = $this->getDocument(ltrim($path, '/')."/$name", $ignoreRole, $class);

            if ($child) {
                return $child;
            }
        }

        return;
    }

    /**
     * Traverse the depth to find previous documents.
     *
     * @return object|null
     */
    private function traversePrevDepth(?int $depth, int $anchorDepth, array $childNames, string $path, bool $ignoreRole, ?string $class)
    {
        foreach ($childNames as $childName) {
            $childPath = "$path/$childName";
            $node = $this->getDm()->getPhpcrSession()->getNode($childPath);
            if (null === $depth || PathHelper::getPathDepth($childPath) - $anchorDepth < $depth) {
                $childNames = $node->getNodeNames()->getArrayCopy();
                if (!empty($childNames)) {
                    $childNames = array_reverse($childNames);
                    $result = $this->traversePrevDepth($depth, $anchorDepth, $childNames, $childPath, $ignoreRole, $class);
                    if ($result) {
                        return $result;
                    }
                }
            }

            $result = $this->checkChildren($childNames, $node->getPath(), $ignoreRole, $class);
            if ($result) {
                return $result;
            }
        }

        return;
    }

    /**
     * Search for a previous document.
     *
     * @param string|object $path       document instance or path from which to search
     * @param string|object $anchor     document instance or path which serves as an anchor from which to flatten the hierarchy
     * @param int|null      $depth      depth up to which to traverse down the tree when an anchor is provided
     * @param bool          $ignoreRole if to ignore the role
     * @param string|null   $class      the class to filter by
     *
     * @return object|null
     */
    private function searchDepthPrev($path, $anchor, ?int $depth = null, ?bool $ignoreRole = false, ?string $class = null)
    {
        if (\is_object($path)) {
            $path = $this->getDm()->getUnitOfWork()->getDocumentId($path);
        }

        if (null === $path || '/' === $path) {
            return;
        }

        $node = $this->getDm()->getPhpcrSession()->getNode($path);

        if (\is_object($anchor)) {
            $anchor = $this->getDm()->getUnitOfWork()->getDocumentId($anchor);
        }

        if (0 !== strpos($path, $anchor)) {
            throw new \RuntimeException("The anchor path '$anchor' is not a parent of the current path '$path'.");
        }

        if ($path === $anchor) {
            return;
        }

        $parent = $node->getParent();
        $parentPath = $parent->getPath();

        $childNames = $parent->getNodeNames()->getArrayCopy();
        if (!empty($childNames)) {
            $childNames = array_reverse($childNames);
            $key = array_search($node->getName(), $childNames);
            $childNames = \array_slice($childNames, $key + 1);

            if (!empty($childNames)) {
                // traverse the previous siblings down the tree
                $result = $this->traversePrevDepth($depth, PathHelper::getPathDepth($anchor), $childNames, $parentPath, $ignoreRole, $class);
                if ($result) {
                    return $result;
                }

                // check siblings
                $result = $this->checkChildren($childNames, $parentPath, $ignoreRole, $class);
                if ($result) {
                    return $result;
                }
            }
        }

        // check parents
        if (0 === strpos($parentPath, $anchor)) {
            $parent = $parent->getParent();
            $childNames = $parent->getNodeNames()->getArrayCopy();
            $key = array_search(PathHelper::getNodeName($parentPath), $childNames);
            $childNames = \array_slice($childNames, 0, $key + 1);
            $childNames = array_reverse($childNames);
            if (!empty($childNames)) {
                $result = $this->checkChildren($childNames, $parent->getPath(), $ignoreRole, $class);
                if ($result) {
                    return $result;
                }
            }
        }

        return;
    }

    /**
     * Search for a next document.
     *
     * @param string|object $path       document instance or path from which to search
     * @param string|object $anchor     document instance or path which serves as an anchor from which to flatten the hierarchy
     * @param int|null      $depth      depth up to which to traverse down the tree when an anchor is provided
     * @param bool          $ignoreRole if to ignore the role
     * @param string|null   $class      the class to filter by
     *
     * @return object|null
     */
    private function searchDepthNext($path, $anchor, ?int $depth = null, ?bool $ignoreRole = false, ?string $class = null)
    {
        if (\is_object($path)) {
            $path = $this->getDm()->getUnitOfWork()->getDocumentId($path);
        }

        if (null === $path || '/' === $path) {
            return;
        }

        $node = $this->getDm()->getPhpcrSession()->getNode($path);

        if (\is_object($anchor)) {
            $anchor = $this->getDm()->getUnitOfWork()->getDocumentId($anchor);
        }

        if (0 !== strpos($path, $anchor)) {
            throw new \RuntimeException("The anchor path '$anchor' is not a parent of the current path '$path'.");
        }

        // take the first eligible child if there are any
        if (null === $depth || PathHelper::getPathDepth($path) - PathHelper::getPathDepth($anchor) < $depth) {
            $childNames = $node->getNodeNames()->getArrayCopy();
            $result = $this->checkChildren($childNames, $path, $ignoreRole, $class);
            if ($result) {
                return $result;
            }
        }

        $parent = $node->getParent();
        $parentPath = PathHelper::getParentPath($path);

        // take the first eligible sibling
        if (0 === strpos($parentPath, $anchor)) {
            $childNames = $parent->getNodeNames()->getArrayCopy();
            $key = array_search($node->getName(), $childNames);
            $childNames = \array_slice($childNames, $key + 1);
            $result = $this->checkChildren($childNames, $parentPath, $ignoreRole, $class);
            if ($result) {
                return $result;
            }
        }

        // take the first eligible parent, traverse up
        while ('/' !== $parentPath) {
            $parent = $parent->getParent();
            if (false === strpos($parent->getPath(), $anchor)) {
                return;
            }

            $childNames = $parent->getNodeNames()->getArrayCopy();
            $key = array_search(PathHelper::getNodeName($parentPath), $childNames);
            $childNames = \array_slice($childNames, $key + 1);
            $parentPath = $parent->getPath();
            $result = $this->checkChildren($childNames, $parentPath, $ignoreRole, $class);
            if ($result) {
                return $result;
            }
        }

        return;
    }

    /**
     * Search for a related document.
     *
     * @param string|object $path       document instance or path from which to search
     * @param bool          $reverse    if to traverse back
     * @param bool          $ignoreRole if to ignore the role
     * @param string|null   $class      the class to filter by
     *
     * @return object|null
     */
    private function search($path, ?bool $reverse = false, ?bool $ignoreRole = false, ?string $class = null)
    {
        if (\is_object($path)) {
            $path = $this->getDm()->getUnitOfWork()->getDocumentId($path);
        }

        if (null === $path || '/' === $path) {
            return;
        }

        $node = $this->getDm()->getPhpcrSession()->getNode($path);
        $parentNode = $node->getParent();
        $childNames = $parentNode->getNodeNames()->getArrayCopy();
        if ($reverse) {
            $childNames = array_reverse($childNames);
        }

        $key = array_search($node->getName(), $childNames);
        $childNames = \array_slice($childNames, $key + 1);

        return $this->checkChildren($childNames, $parentNode->getPath(), $ignoreRole, $class);
    }

    /**
     * Gets the previous document.
     *
     * @param string|object      $current    document instance or path from which to search
     * @param string|object|null $anchor     document instance or path which serves as an anchor from which to flatten the hierarchy
     * @param int|null           $depth      depth up to which to traverse down the tree when an anchor is provided
     * @param bool               $ignoreRole if to ignore the role
     * @param string|null        $class      the class to filter by
     *
     * @return object|null
     */
    public function getPrev($current, $anchor = null, ?int $depth = null, ?bool $ignoreRole = false, ?string $class = null)
    {
        if ($anchor) {
            return $this->searchDepthPrev($current, $anchor, $depth, $ignoreRole, $class);
        }

        return $this->search($current, true, $ignoreRole, $class);
    }

    /**
     * Gets the next document.
     *
     * @param string|object      $current    document instance or path from which to search
     * @param string|object|null $anchor     document instance or path which serves as an anchor from which to flatten the hierarchy
     * @param int|null           $depth      depth up to which to traverse down the tree when an anchor is provided
     * @param bool               $ignoreRole if to ignore the role
     * @param string|null        $class      the class to filter by
     *
     * @return object|null
     */
    public function getNext($current, $anchor = null, ?int $depth = null, ?bool $ignoreRole = false, ?string $class = null)
    {
        if ($anchor) {
            return $this->searchDepthNext($current, $anchor, $depth, $ignoreRole, $class);
        }

        return $this->search($current, false, $ignoreRole, $class);
    }

    /**
     * Gets the previous linkable document.
     *
     * This has the same semantics as the isLinkable method.
     *
     * @param string|object      $current    Document instance or path from
     *                                       which to search
     * @param string|object|null $anchor     Document instance or path which
     *                                       serves as an anchor from which to
     *                                       flatten the hierarchy
     * @param int|null           $depth      Depth up to which to traverse down
     *                                       the tree when an anchor is
     *                                       provided
     * @param bool               $ignoreRole Whether to ignore the role,
     *
     * @return object|null
     *
     * @see isLinkable
     */
    public function getPrevLinkable($current, $anchor = null, ?int $depth = null, ?bool $ignoreRole = false)
    {
        while ($candidate = $this->getPrev($current, $anchor, $depth, $ignoreRole)) {
            if ($this->isLinkable($candidate)) {
                return $candidate;
            }

            $current = $candidate;
        }
    }

    /**
     * Gets the next linkable document.
     *
     * This has the same semantics as the isLinkable method.
     *
     * @param string|object      $current    Document instance or path from
     *                                       which to search
     * @param string|object|null $anchor     Document instance or path which
     *                                       serves as an anchor from which to
     *                                       flatten the hierarchy
     * @param int|null           $depth      Depth up to which to traverse down
     *                                       the tree when an anchor is
     *                                       provided
     * @param bool               $ignoreRole Whether to ignore the role
     *
     * @return object|null
     *
     * @see isLinkable
     */
    public function getNextLinkable($current, $anchor = null, ?int $depth = null, ?bool $ignoreRole = false)
    {
        while ($candidate = $this->getNext($current, $anchor, $depth, $ignoreRole)) {
            if ($this->isLinkable($candidate)) {
                return $candidate;
            }

            $current = $candidate;
        }
    }
}
