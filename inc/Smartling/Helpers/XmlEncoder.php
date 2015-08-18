<?php

namespace Smartling\Helpers;

use DOMAttr;
use DOMCdataSection;
use DOMDocument;
use DOMXPath;
use Smartling\Bootstrap;
use Smartling\Exception\InvalidXMLException;

/**
 * Class XmlEncoder
 *
 * Encodes given array into XML string and backward
 *
 * @package Smartling\Processors
 */
class XmlEncoder {

	private static $magicComments = array (
		'smartling.translate_paths = data/string',
		'smartling.string_format_paths = html : data/string',
		'smartling.source_key_paths = data/{string.key}',
		'smartling.variants_enabled = true',
	);

	const XML_ROOT_NODE_NAME = 'data';

	const XML_STRING_NODE_NAME = 'string';

	const XML_SOURCE_NODE_NAME = 'source';

	/**
	 * @return DOMDocument
	 */
	private static function initXml () {
		$xml = new DOMDocument( '1.0', 'UTF-8' );

		return $xml;
	}

	/**
	 * Sets comments about translation type (html)
	 *
	 * @param DOMDocument $document
	 *
	 * @return DOMDocument
	 */
	private static function setTranslationComments ( DOMDocument $document ) {
		foreach ( self::$magicComments as $commentString ) {
			$document->appendChild( $document->createComment( vsprintf( ' %s ', array ( $commentString ) ) ) );
		}

		$document->appendChild(
			$document->createComment(
				vsprintf(
					' %s ',
					array (
						'Smartling Wordpress Connector v. ' . Bootstrap::getCurrentVersion()
					)
				)
			)
		);

		return $document;
	}

	/**
	 * @param array  $array
	 * @param string $base
	 * @param string $divider
	 *
	 * @return array
	 */
	protected static function flatternArray ( array $array, $base = '', $divider = '/' ) {
		$output = array ();

		foreach ( $array as $key => $element ) {

			$path = '' === $base ? $key : implode( $divider, array ( $base, $key ) );

			if ( is_array( $element ) ) {

				$tmp    = self::flatternArray( $element, $path );
				$output = array_merge( $output, $tmp );
			} else {
				$output[ $path ] = (string) $element;
			}
		}

		return $output;
	}

	/**
	 * @param array  $flatArray
	 * @param string $divider
	 *
	 * @return array
	 */
	protected static function structurizeArray ( array $flatArray, $divider = '/' ) {
		$output = array ();

		foreach ( $flatArray as $key => $element ) {
			$pathElements = explode( $divider, $key );
			$pointer      = &$output;
			for ( $i = 0; $i < ( count( $pathElements ) - 1 ); $i ++ ) {
				if ( ! isset( $pointer[ $pathElements[ $i ] ] ) ) {
					$pointer[ $pathElements[ $i ] ] = array ();
				}
				$pointer = &$pointer[ $pathElements[ $i ] ];
			}
			$pointer[ end( $pathElements ) ] = $element;
		}

		return $output;
	}

	/**
	 * @param array $source
	 *
	 * @return array
	 */
	private static function normalizeSource ( array $source ) {
		$pointer = &$source['meta'];
		foreach ( $pointer as & $value ) {
			if ( is_array( $value ) && 1 === count( $value ) ) {
				$value = reset( $value );
			}
		}

		return $source;
	}

	/**
	 * @return mixed
	 */
	private static function getFieldProcessingParams () {
		return Bootstrap::getContainer()->getParameter( 'field.processor' );
	}

	private static function removeFields ( $array, $list ) {

		$rebuild = array ();
		foreach ( $array as $key => $value ) {
			foreach ( $list as $item ) {

				if ( false !== strpos( $key, urlencode( $item ) ) ) {
					continue 2;
				}
			}
			$rebuild[ $key ] = $value;
		}

		return $rebuild;
	}

	/**
	 * @param $array
	 *
	 * @return array
	 */
	private static function removeEmptyFields ( $array ) {
		$rebuild = array ();
		foreach ( $array as $key => $value ) {
			if ( empty( $value ) ) {
				continue;
			}
			$rebuild[ $key ] = $value;
		}

		return $rebuild;
	}

	/**
	 * @param $array
	 * @param $list
	 *
	 * @return array
	 */
	private static function removeValuesByRegExp ( $array, $list ) {
		$rebuild = array ();
		foreach ( $array as $key => $value ) {
			foreach ( $list as $item ) {
				if ( preg_match( "/{$item}/ius", $value ) ) {
					continue 2;
				}
			}
			$rebuild[ $key ] = $value;
		}

		return $rebuild;
	}

	private static function prepareSourceArray ( $sourceArray, $strategy = 'send' ) {
		$sourceArray = self::normalizeSource( $sourceArray );

		/*foreach ( $sourceArray as & $value ) {
			if ( false !== ( $tmp = @unserialize( $value ) ) ) {
				$value = $tmp;
			}
		}*/

		foreach ( $sourceArray['meta'] as & $value ) {
			if ( is_array( $value ) && array_key_exists( 'entity', $value ) && array_key_exists( 'meta', $value ) ) {
				// nested object detected
				$value = self::prepareSourceArray( $value, $strategy );
			}

			if ( false !== ( $tmp = @unserialize( $value ) ) ) {
				$value = $tmp;
			}
		}

		$sourceArray = self::flatternArray( $sourceArray );

		$settings = self::getFieldProcessingParams();

		if ( 'send' === $strategy ) {
			$sourceArray = self::removeFields( $sourceArray, $settings['ignore'] );
			$sourceArray = self::removeFields( $sourceArray, $settings['copy']['name'] );
			$sourceArray = self::removeValuesByRegExp( $sourceArray, $settings['copy']['regexp'] );
			$sourceArray = self::removeEmptyFields( $sourceArray );
		}

		return $sourceArray;

	}

	private static function encodeSource ( $source, $stringLength = 80 ) {
		return implode( "\n", str_split( base64_encode( serialize( $source ) ), $stringLength ) );
	}

	private static function decodeSource ( $source ) {
		return unserialize( base64_decode( $source ) );
	}

	/**
	 * @param array $source
	 *
	 * @return string
	 */
	public static function xmlEncode ( array $source ) {
		$originalSource = $source;
		$source         = self::prepareSourceArray( $source );
		$xml            = self::setTranslationComments( self::initXml() );
		$settings       = self::getFieldProcessingParams();
		$keySettings    = &$settings['key'];
		$rootNode       = $xml->createElement( self::XML_ROOT_NODE_NAME );
		foreach ( $source as $name => $value ) {
			$rootNode->appendChild( self::rowToXMLNode( $xml, $name, $value, $keySettings ) );
		}
		$xml->appendChild( $rootNode );
		$sourceNode = $xml->createElement( self::XML_SOURCE_NODE_NAME );
		$sourceNode->appendChild( new DOMCdataSection( self::encodeSource( $originalSource ) ) );
		$rootNode->appendChild( $sourceNode );

		return $xml->saveXML();
	}

	/**
	 * @inheritdoc
	 */
	private static function rowToXMLNode ( DOMDocument $document, $name, $value, & $keySettings ) {
		$node = $document->createElement( self::XML_STRING_NODE_NAME );
		$node->appendChild( new DOMAttr( 'name', $name ) );
		foreach ( $keySettings as $key => $fields ) {
			foreach ( $fields as $field ) {
				if ( false !== strpos( $name, $field ) ) {
					$node->appendChild( new DOMAttr( 'key', $key ) );
				}
			}
		}
		$node->appendChild( new DOMCdataSection( $value ) );

		return $node;
	}

	/**
	 * @param string $xmlString
	 *
	 * @return DOMXPath
	 * @throws InvalidXMLException
	 */
	private static function prepareXPath ( $xmlString ) {
		$xml    = self::initXml();
		$result = @$xml->loadXML( $xmlString );
		if ( false === $result ) {
			throw new InvalidXMLException( 'Invalid XML Contents' );
		}
		$xpath = new DOMXPath( $xml );

		return $xpath;
	}

	public static function xmlDecode ( $content ) {
		$xpath = self::prepareXPath( $content );

		$stringPath = '/data/string';
		$sourcePath = '/data/source';

		$nodeList = $xpath->query( $stringPath );

		$fields = array ();

		for ( $i = 0; $i < $nodeList->length; $i ++ ) {
			$item            = $nodeList->item( $i );
			$name            = $item->getAttribute( 'name' );
			$value           = $item->nodeValue;
			$fields[ $name ] = $value;
		}

		$nodeList = $xpath->query( $sourcePath );

		$source = self::decodeSource( $nodeList->item( 0 )->nodeValue );

		$flatSource = self::prepareSourceArray( $source, 'download' );

		foreach ( $fields as $key => $value ) {
			$flatSource[ $key ] = $value;
		}

		foreach ( $flatSource as & $value ) {
			if ( is_numeric( $value ) && is_string( $value ) ) {
				$value += 0;
			}
		}

		$settings   = self::getFieldProcessingParams();
		$flatSource = self::removeFields( $flatSource, $settings['ignore'] );

		return self::structurizeArray( $flatSource );;
	}
}