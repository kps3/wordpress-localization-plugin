<?php

namespace Smartling\Helpers;

use Smartling\Base\ExportedAPI;
use Smartling\Exception\SmartlingGutenbergNotFoundException;
use Smartling\Helpers\EventParameters\TranslationStringFilterParameters;

/**
 * Class SubstringProcessorHelperAbstract
 * @package Smartling\Helpers
 */
class GutenbergBlockHelper extends SubstringProcessorHelperAbstract
{

    const BLOCK_NODE_NAME = 'gutenbergBlock';

    const CHUNK_NODE_NAME = 'contentChunk';

    const ATTRIBUTE_NODE_NAME = 'blockAttribute';

    /**
     * Registers wp hook handlers. Invoked by wordpress.
     * @return void
     */
    public function register()
    {
        $handlers = [
            ExportedAPI::FILTER_SMARTLING_TRANSLATION_STRING => 'processString',
            ExportedAPI::FILTER_SMARTLING_TRANSLATION_STRING_RECEIVED => 'processTranslation',
        ];

        try {
            $this->loadBlockClass();

            foreach ($handlers as $hook => $handler) {
                add_filter($hook, [$this, $handler]);
            }

        } catch (SmartlingGutenbergNotFoundException $e) {
            $this->getLogger()->notice($e->getMessage());
        }
    }

    /**
     * @param $blockName
     * @param array $flatAttributes
     * @return array
     */
    public function processAttributes($blockName, array $flatAttributes)
    {
        $attributes = [];
        if (null !== $blockName && 0 < count($flatAttributes)) {
            $logMsg = vsprintf(
                'Pre filtered block \'%s\' attributes \'%s\'', [$blockName, var_export($flatAttributes, true)]
            );
            $this->getLogger()->debug($logMsg);
            $prepAttributes = self::maskAttributes($blockName, $flatAttributes);
            $this->postReceiveFiltering($prepAttributes);
            $prepAttributes = $this->preSendFiltering($prepAttributes);
            $logMsg = vsprintf(
                'Post filtered block \'%s\' attributes \'%s\'', [$blockName, var_export($prepAttributes, true)]
            );
            $this->getLogger()->debug($logMsg);
            $attributes = self::unmaskAttributes($blockName, $prepAttributes);
        } else {
            $this->getLogger()->debug(vsprintf('No attributes found in block \'%s\'.', [$blockName]));
        }
        return $attributes;
    }

    private function hasBlocks($string)
    {
        return (false !== strpos($string, '<!-- wp:'));
    }

    private function packData(array $data)
    {
        return base64_encode(serialize($data));
    }

    private function unpackData($data)
    {
        return unserialize(base64_decode($data));
    }

    /**
     * @param array $block
     * @return \DOMElement
     */
    private function placeBlock(array $block)
    {
        $indexPointer = 0;

        $node = self::createDomNode(
            static::BLOCK_NODE_NAME,
            [
                'blockName' => $block['blockName'],
                'originalAttributes' => $this->packData($block['attrs'])
            ],
            ''
        );

        foreach ($block['innerContent'] as $chunk) {
            $part = null;
            if (is_string($chunk)) {
                $part = self::createDomNode(static::CHUNK_NODE_NAME, ['hash' => md5($chunk)], $chunk);
            } else {
                $part = $this->placeBlock($block['innerBlocks'][$indexPointer++]);
            }
            $node->appendChild($part);
        }

        $flatAttributes = $this->getFieldsFilter()->flatternArray($block['attrs']);
        $filteredAttributes = $this->processAttributes($block['blockName'], $flatAttributes);
        foreach ($filteredAttributes as $attrName => $attrValue) {
            $arrtNode = self::createDomNode(static::ATTRIBUTE_NODE_NAME, [
                'name' => $attrName,
                'hash' => md5($attrValue),
            ], $attrValue);
            $node->appendChild($arrtNode);
        }

        return $node;
    }

    /**
     * Filter handler
     *
     * @param TranslationStringFilterParameters $params
     *
     * @return TranslationStringFilterParameters
     */
    public function processString(TranslationStringFilterParameters $params)
    {
        $this->subNodes = [];
        $this->setParams($params);
        $string = self::getCdata($params->getNode());
        if (!$this->hasBlocks($string)) {
            return $params;
        }
        $originalBlocks = gutenberg_parse_blocks($string);

        foreach ($originalBlocks as $block) {
            $node = $this->placeBlock($block);
            $params->getNode()->appendChild($node);
        }
        self::replaceCData($params->getNode(), '');

        return $params;
    }

    /**
     * @param \DOMNode $node
     * @param array $chunks
     * @param array $attrs
     */
    private function sortChildNodesContent(\DOMNode $node, array & $chunks, array & $attrs)
    {
        $chunks = [];
        $attrs = [];

        foreach ($node->childNodes as $childNode) {
            /**
             * @var \DOMNode $childNode
             */

            switch ($childNode->nodeName) {
                case static::BLOCK_NODE_NAME :
                    $chunks[] = $this->renderTranslatedBlockNode($childNode);
                    break;
                case static::CHUNK_NODE_NAME :
                    $chunks[] = $childNode->nodeValue;
                    break;
                case static::ATTRIBUTE_NODE_NAME :
                    $attrs[$childNode->getAttribute('name')] = $childNode->nodeValue;
                    break;
                default:
                    $this->getLogger()->notice(
                        vsprintf(
                            'Got unexpected child with name=\'%s\' while applying translation.',
                            [$childNode->nodeName]
                        )
                    );
                    break;
            }

            $node->removeChild($childNode);
        }
    }

    /**
     * @param string $blockName
     * @param array $originalAttributes
     * @param array $translatedAttributes
     * @return array
     */
    private function processTranslationAttributes($blockName, $originalAttributes, $translatedAttributes)
    {
        if (0 < count($originalAttributes)) {
            $flatAttributes = $this->getFieldsFilter()->flatternArray($originalAttributes);

            if (0 < count($flatAttributes)) {
                $attr = self::maskAttributes($blockName, $flatAttributes);
                $attr = $this->postReceiveFiltering($attr);
                $attr = self::unmaskAttributes($blockName, $attr);
            }

            $filteredAttributes = array_merge($flatAttributes, $attr, $translatedAttributes);

            $processedAttributes = $this->getFieldsFilter()->structurizeArray($filteredAttributes);
        } else {
            $processedAttributes = [];
        }

        return $processedAttributes;
    }

    public function renderTranslatedBlockNode(\DOMNode $node)
    {
        $blockName = $node->getAttribute('blockName');
        $blockName = '' === $blockName ? null : $blockName;

        $originalAttributesEncoded = $node->getAttribute('originalAttributes');

        $chunks = [];
        $translatedAttributes = [];

        $this->sortChildNodesContent($node, $chunks, $translatedAttributes);

        // simple plain blocks
        if (null === $blockName) {
            return implode('', $chunks);
        }

        $originalAttributes = $this->unpackData($originalAttributesEncoded);

        $processedAttributes = $this->processTranslationAttributes($blockName, $originalAttributes,
            $translatedAttributes);

        return $this->renderGutenbergBlock($blockName, $processedAttributes, $chunks);
    }

    /**
     * @param string $name
     * @param array $attrs
     * @param array $chunks
     * @return string
     */
    private function renderGutenbergBlock($name, array $attrs = [], array $chunks = [])
    {
        $renderedBlock = (0 < count($chunks))
            ? vsprintf(
                '<!-- wp:%s%s -->%s<!-- /wp:%s -->',
                [
                    $name,
                    (0 < count($attrs) ? ' ' . json_encode($attrs) : ''),
                    implode('', $chunks),
                    $name
                ]
            )
            : vsprintf(
                '<!-- wp:%s%s /-->',
                [
                    $name,
                    (0 < count($attrs) ? ' ' . json_encode($attrs) : ''),
                ]
            );

        return $renderedBlock;
    }

    /**
     * Filter handler
     *
     * @param TranslationStringFilterParameters $params
     *
     * @return TranslationStringFilterParameters
     */
    public function processTranslation(TranslationStringFilterParameters $params)
    {
        $this->setParams($params);
        $node = $this->getNode();
        $string = static::getCdata($node);

        /**
         * @var \DOMNodeList $children
         */
        $children = $node->childNodes;

        if ('' === $string) {
            foreach ($children as $child) {
                /**
                 * @var \DOMNode $child
                 */
                if ('gutenbergBlock' === $child->nodeName) {
                    $string .= $this->renderTranslatedBlockNode($child);
                    $node->removeChild($child);
                }
            }
        }

        self::replaceCData($params->getNode(), $string);
        return $this->getParams();
    }

    /**
     * Removes smartling masks from the string
     */
    protected function unmask()
    {
    }

    /**
     * @throws SmartlingGutenbergNotFoundException
     */
    private function loadBlockClass()
    {
        $paths = [
            vsprintf('%swp-includes/blocks.php', [ABSPATH]),
            vsprintf('%swp-content/plugins/gutenberg/lib/blocks.php', [ABSPATH])
        ];

        foreach ($paths as $path) {
            //$this->getLogger()->debug(vsprintf('Trying to get block class from file: %s', [$path]));
            if (file_exists($path) && is_readable($path)) {
                /** @noinspection PhpIncludeInspection */
                require_once $path;
                return;
            }
        }

        throw new SmartlingGutenbergNotFoundException("Gutenberg class not found. Disabling GutenbergSupport.");
    }
}
