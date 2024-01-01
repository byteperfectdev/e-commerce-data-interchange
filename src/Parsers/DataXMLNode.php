<?php declare( strict_types=1 );
/**
 * Часть 1С-Битрикс
 * bitrix/modules/main/classes/general/xml.php
 */

namespace BytePerfect\EDI\Parsers;

class DataXMLNode {
	var $name;
	var $content;
	/** @var DataXMLNode[] */
	var $children;
	/** @var DataXMLNode[] */
	var $attributes;
	var $_parent;

	public function __construct() {
	}

	function name() {
		return $this->name;
	}

	function children() {
		return $this->children;
	}

	function textContent() {
		return $this->content;
	}

	function getAttribute( $attribute ) {
		if ( is_array( $this->attributes ) ) {
			foreach ( $this->attributes as $anode ) {
				if ( $anode->name == $attribute ) {
					return $anode->content;
				}
			}
		}

		return "";
	}

	function getAttributes() {
		return $this->attributes;
	}

	function namespaceURI() {
		return $this->getAttribute( "xmlns" );
	}

	/**
	 * @param $tagname
	 *
	 * @return DataXMLNode[]
	 */
	function elementsByName( $tagname ) {
		$result = array();

		if ( $this->name == $tagname ) {
			array_push( $result, $this );
		}

		if ( is_array( $this->children ) ) {
			foreach ( $this->children as $node ) {
				$more = $node->elementsByName( $tagname );
				if ( is_array( $more ) ) {
					foreach ( $more as $mnode ) {
						array_push( $result, $mnode );
					}
				}
			}
		}

		return $result;
	}

	function _SaveDataType_OnDecode( &$result, $name, $value ) {
		if ( isset( $result[ $name ] ) ) {
			$i = 1;
			while ( isset( $result[ $i . ":" . $name ] ) ) {
				$i ++;
			}
			$result[ $i . ":" . $name ] = $value;

			return "indexed";
		} else {
			$result[ $name ] = $value;

			return "common";
		}
	}

	function decodeDataTypes( $attrAsNodeDecode = false ) {
		$result = array();

		if ( ! $this->children ) {
			$this->_SaveDataType_OnDecode( $result, $this->name(), $this->textContent() );
		} else {
			foreach ( $this->children() as $child ) {
				$cheese = $child->children();
				if ( ! $cheese or ! count( $cheese ) ) {
					$this->_SaveDataType_OnDecode( $result, $child->name(), $child->textContent() );
				} else {
					$cheresult = $child->decodeDataTypes();
					if ( is_array( $cheresult ) ) {
						$this->_SaveDataType_OnDecode( $result, $child->name(), $cheresult );
					}
				}
			}
		}

		if ( $attrAsNodeDecode ) {
			foreach ( $this->getAttributes() as $child ) {
				$this->_SaveDataType_OnDecode( $result, $child->name(), $child->textContent() );
			}
		}

		return $result;
	}

	function &__toString() {
		switch ( $this->name ) {
			case "cdata-section":
				$ret = "<![CDATA[";
				$ret .= $this->content;
				$ret .= "]]>";
				break;

			default:
				$isOneLiner = false;

				if ( count( $this->children ) == 0 && $this->content == '' ) {
					$isOneLiner = true;
				}

				$attrStr = "";

				if ( is_array( $this->attributes ) ) {
					foreach ( $this->attributes as $attr ) {
						$attrStr .= " " . $attr->name . "=\"" . DataXML::xmlspecialchars( $attr->content ) . "\" ";
					}
				}

				if ( $isOneLiner ) {
					$oneLinerEnd = " /";
				} else {
					$oneLinerEnd = "";
				}

				$ret = "<" . $this->name . $attrStr . $oneLinerEnd . ">";

				if ( is_array( $this->children ) ) {
					foreach ( $this->children as $child ) {
						$ret .= $child->__toString();
					}
				}

				if ( ! $isOneLiner ) {
					if ( $this->content <> '' ) {
						$ret .= DataXML::xmlspecialchars( $this->content );
					}

					$ret .= "</" . $this->name . ">";
				}

				break;
		}

		return $ret;
	}

	function __toArray() {
		$retHash = array(
			"@" => array(),
		);

		if ( is_array( $this->attributes ) ) {
			foreach ( $this->attributes as $attr ) {
				$retHash["@"][ $attr->name ] = $attr->content;
			}
		}

		if ( $this->content != "" ) {
			$retHash["#"] = $this->content;
		} elseif ( ! empty( $this->children ) ) {
			$ar = array();
			foreach ( $this->children as $child ) {
				$ar[ $child->name ][] = $child->__toArray();
			}
			$retHash["#"] = $ar;
		} else {
			$retHash["#"] = "";
		}

		return $retHash;
	}
}
