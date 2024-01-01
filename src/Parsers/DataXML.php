<?php declare( strict_types=1 );
/**
 * Часть 1С-Битрикс
 * bitrix/modules/main/classes/general/xml.php
 */

namespace BytePerfect\EDI\Parsers;

use Exception;

class DataXML {
	/** @var DataXMLDocument */
	var $tree;
	protected bool $trim_whitespace;

	var $delete_ns = true;

	public function __construct( bool $trim_whitespace = true ) {
		$this->trim_whitespace = $trim_whitespace;
		$this->tree            = false;
	}

	function Load( $file ) {
		unset( $this->tree );
		$this->tree = false;

		if ( file_exists( $file ) ) {
			$content = file_get_contents( $file );
			$charset = ( defined( "BX_DEFAULT_CHARSET" ) ? BX_DEFAULT_CHARSET : "windows-1251" );
			if ( preg_match( "/<" . "\\?XML[^>]{1,}encoding=[\"']([^>\"']{1,})[\"'][^>]{0,}\\?" . ">/i",
				$content, $matches ) ) {
				$charset = trim( $matches[1] );
			}
//			$content             = Encoding::convertEncoding($content, $charset, SITE_CHARSET);
			$c_data_XML_document = $this->__parse( $content );
			$this->tree          = &$c_data_XML_document;

			return $this->tree !== false;
		}

		return false;
	}

	/**
	 * @throws Exception
	 */
	public function load_string( string $text ) {
		if ( empty( $text ) ) {
			throw new Exception( __( 'Load from empty string.', 'edi' ) );
		}

		// @todo w8: Перенести вызов исключения в функцию $this->__parse().
		if ( ! $this->__parse( $text ) ) {
			throw new Exception( __( 'Error parsing loaded string.', 'edi' ) );
		}
	}

	function &GetTree() {
		return $this->tree;
	}

	function &GetArray() {
		if ( ! is_object( $this->tree ) ) {
			$f = false;

			return $f;
		} else {
			return $this->tree->__toArray();
		}
	}

	function &GetString() {
		if ( ! is_object( $this->tree ) ) {
			$f = false;

			return $f;
		} else {
			return $this->tree->__toString();
		}
	}

	function &SelectNodes( $strNode ) {
		if ( ! is_object( $this->tree ) ) {
			$f = false;

			return $f;
		}

		$result = &$this->tree;

		$tmp      = explode( "/", $strNode );
		$tmpCount = count( $tmp );
		for ( $i = 1; $i < $tmpCount; $i ++ ) {
			if ( $tmp[ $i ] != "" ) {
				if ( ! is_array( $result->children ) ) {
					$f = false;

					return $f;
				}

				$bFound = false;
				for ( $j = 0, $c = count( $result->children ); $j < $c; $j ++ ) {
					if ( $result->children[ $j ]->name == $tmp[ $i ] ) {
						$result = &$result->children[ $j ];
						$bFound = true;
						break;
					}
				}

				if ( ! $bFound ) {
					$f = false;

					return $f;
				}
			}
		}

		return $result;
	}

	public static function xmlspecialchars( $str ) {
		static $search = array( "&", "<", ">", "\"", "'" );
		static $replace = array( "&amp;", "&lt;", "&gt;", "&quot;", "&apos;" );

		return str_replace( $search, $replace, $str );
	}

	public static function xmlspecialcharsback( $str ) {
		static $search = array( "&lt;", "&gt;", "&quot;", "&apos;", "&amp;" );
		static $replace = array( "<", ">", "\"", "'", "&" );

		return str_replace( $search, $replace, $str );
	}

	/**
	 * Will return an DOM object tree from the well-formed XML.
	 *
	 * @param string $strXMLText
	 *
	 * @return bool
	 */
	protected function __parse( &$strXMLText ): bool {
		static $search = array( "&gt;", "&lt;", "&apos;", "&quot;", "&amp;" );
		static $replace = array( ">", "<", "'", '"', "&" );

		$oXMLDocument = new DataXMLDocument();

		// strip comments
		$strip_comments = DataXML::__stripComments( $strXMLText );
		$strXMLText     = &$strip_comments;

		// stip the !doctype
		// The DOCTYPE declaration can consists of an internal DTD in square brackets
		$cnt        = 0;
		$strXMLText = preg_replace( "%<\\!DOCTYPE[^\\[>]*\\[.*?\\]>%is", "", $strXMLText, - 1, $cnt );
		if ( $cnt == 0 ) {
			$strXMLText = preg_replace( "%<\\!DOCTYPE[^>]*>%is", "", $strXMLText );
		}

		// get document version and encoding from header
		preg_match_all( "#<\\?(.*?)\\?>#i", $strXMLText, $arXMLHeader_tmp );
		foreach ( $arXMLHeader_tmp[0] as $strXMLHeader_tmp ) {
			preg_match_all( "/([a-zA-Z:]+=\".*?\")/i", $strXMLHeader_tmp, $arXMLParam_tmp );
			foreach ( $arXMLParam_tmp[0] as $strXMLParam_tmp ) {
				if ( $strXMLParam_tmp <> '' ) {
					$arXMLAttribute_tmp = explode( "=\"", $strXMLParam_tmp );
					if ( $arXMLAttribute_tmp[0] == "version" ) {
						$oXMLDocument->version = mb_substr( $arXMLAttribute_tmp[1], 0,
							mb_strlen( $arXMLAttribute_tmp[1] ) - 1 );
					} elseif ( $arXMLAttribute_tmp[0] == "encoding" ) {
						$oXMLDocument->encoding = mb_substr( $arXMLAttribute_tmp[1], 0,
							mb_strlen( $arXMLAttribute_tmp[1] ) - 1 );
					}
				}
			}
		}

		// strip header
		$preg_replace = preg_replace( "#<\\?.*?\\?>#", "", $strXMLText );
		$strXMLText   = &$preg_replace;

		$oXMLDocument->root = &$oXMLDocument->children;

		/** @var DataXMLNode $currentNode */
		$currentNode = &$oXMLDocument;

		$tok   = strtok( $strXMLText, "<" );
		$arTag = explode( ">", $tok );
		if ( count( $arTag ) < 2 ) {
			//There was whitespace before <, so make another try
			$tok   = strtok( "<" );
			$arTag = explode( ">", $tok );
			if ( count( $arTag ) < 2 ) {
				//It's a broken XML
				return false;
			}
		}

		while ( $tok !== false ) {
			$tagName    = $arTag[0];
			$tagContent = $arTag[1];

			// find tag name with attributes
			// check if it's an endtag </tagname>
			if ( $tagName[0] == "/" ) {
				$tagName = mb_substr( $tagName, 1 );
				// strip out namespace; nameSpace:Name
				if ( $this->delete_ns ) {
					$colonPos = mb_strpos( $tagName, ":" );

					if ( $colonPos > 0 ) {
						$tagName = mb_substr( $tagName, $colonPos + 1 );
					}
				}

				if ( $currentNode->name != $tagName ) {
					// Error parsing XML, unmatched tags $tagName
					return false;
				}

				$currentNode = $currentNode->_parent;

				// convert special chars
				if ( ( ! $this->trim_whitespace ) || ( trim( $tagContent ) != "" ) ) {
					$currentNode->content = str_replace( $search, $replace, $tagContent );
				}
			} elseif ( strncmp( $tagName, "![CDATA[", 8 ) === 0 ) {
				//because cdata may contain > and < chars
				//it is special processing needed
				$cdata = "";
				for ( $i = 0, $c = count( $arTag ); $i < $c; $i ++ ) {
					$cdata .= $arTag[ $i ] . ">";
					if ( mb_substr( $cdata, - 3 ) == "]]>" ) {
						$tagContent = $arTag[ $i + 1 ];
						break;
					}
				}

				if ( mb_substr( $cdata, - 3 ) != "]]>" ) {
					$cdata = mb_substr( $cdata, 0, - 1 ) . "<";
					do {
						$tok   = strtok( ">" );//unfortunatly strtok eats > followed by >
						$cdata .= $tok . ">";
						//util end of string or end of cdata found
					} while ( $tok !== false && mb_substr( $tok, - 2 ) != "]]" );
					//$tagName = substr($tagName, 0, -1);
				}

				$cdataSection = mb_substr( $cdata, 8, - 3 );

				// new CDATA node
				$subNode          = new DataXMLNode();
				$subNode->name    = "cdata-section";
				$subNode->content = $cdataSection;

				$currentNode->children[] = $subNode;
				$currentNode->content    .= $subNode->content;

				// convert special chars
				if ( ( ! $this->trim_whitespace ) || ( trim( $tagContent ) != "" ) ) {
					$currentNode->content = str_replace( $search, $replace, $tagContent );
				}
			} else {
				// normal start tag
				$firstSpaceEnd   = mb_strpos( $tagName, " " );
				$firstNewlineEnd = mb_strpos( $tagName, "\n" );

				if ( $firstNewlineEnd != false ) {
					if ( $firstSpaceEnd != false ) {
						$tagNameEnd = min( $firstSpaceEnd, $firstNewlineEnd );
					} else {
						$tagNameEnd = $firstNewlineEnd;
					}
				} else {
					if ( $firstSpaceEnd != false ) {
						$tagNameEnd = $firstSpaceEnd;
					} else {
						$tagNameEnd = 0;
					}
				}

				if ( $tagNameEnd > 0 ) {
					$justName = mb_substr( $tagName, 0, $tagNameEnd );
				} else {
					$justName = $tagName;
				}

				// strip out namespace; nameSpace:Name
				if ( $this->delete_ns ) {
					$colonPos = mb_strpos( $justName, ":" );

					if ( $colonPos > 0 ) {
						$justName = mb_substr( $justName, $colonPos + 1 );
					}
				}

				// remove trailing / from the name if exists
				$justName = rtrim( $justName, "/" );

				$subNode          = new DataXMLNode();
				$subNode->_parent = $currentNode;
				$subNode->name    = $justName;

				// find attributes
				if ( $tagNameEnd > 0 ) {
					$attributePart = mb_substr( $tagName, $tagNameEnd );

					// attributes
					unset( $attr );
					$attr = DataXML::__parseAttributes( $attributePart );

					if ( $attr != false ) {
						$subNode->attributes = $attr;
					}
				}

				// convert special chars
				if ( ( ! $this->trim_whitespace ) || ( trim( $tagContent ) != "" ) ) {
					$subNode->content = str_replace( $search, $replace, $tagContent );
				}

				$currentNode->children[] = $subNode;

				if ( mb_substr( $tagName, - 1 ) != "/" ) {
					$currentNode = $subNode;
				}
			}

			//Next iteration
			$tok = strtok( "<" );
			if ( $tok ) {
				$arTag = explode( ">", $tok );
				//There was whitespace before < just after CDATA section, so make another try
				if ( count( $arTag ) < 2 && ( strncmp( $tagName, "![CDATA[", 8 ) === 0 ) ) {
					$currentNode->content .= $arTag[0];

					// convert special chars
					if ( ( ! $this->trim_whitespace ) || ( trim( $tagContent ) != "" ) ) {
						$currentNode->content = str_replace( $search, $replace, $tagContent );
					}

					$tok   = strtok( "<" );
					$arTag = explode( ">", $tok );
				}
			}
		}

		$this->tree = $oXMLDocument;

		return true;
	}

	function __stripComments( &$str ) {
		$preg_replace = preg_replace( "#<\\!--.*?-->#s", "", $str );
		$str          = &$preg_replace;

		return $str;
	}

	/* Parses the attributes. Returns false if no attributes in the supplied string is found */
	function &__parseAttributes( $attributeString ) {
		$ret = false;

		preg_match_all( "/(\\S+)\\s*=\\s*([\"'])(.*?)\\2/su", $attributeString,
			$attributeArray );

		foreach ( $attributeArray[0] as $i => $attributePart ) {
			$attributePart = trim( $attributePart );
			if ( $attributePart != "" && $attributePart != "/" ) {
				$attributeName = $attributeArray[1][ $i ];

				// strip out namespace; nameSpace:Name
				if ( $this->delete_ns ) {
					$colonPos = mb_strpos( $attributeName, ":" );

					if ( $colonPos > 0 ) {
						// exclusion: xmlns attribute is xmlns:nameSpace
						if ( $colonPos == 5 && ( mb_substr( $attributeName, 0, $colonPos ) == 'xmlns' ) ) {
							$attributeName = 'xmlns';
						} else {
							$attributeName = mb_substr( $attributeName, $colonPos + 1 );
						}
					}
				}
				$attributeValue = $attributeArray[3][ $i ];

				unset( $attrNode );
				$attrNode          = new DataXMLNode();
				$attrNode->name    = $attributeName;
				$attrNode->content = DataXML::xmlspecialcharsback( $attributeValue );

				$ret[] = &$attrNode;
			}
		}

		return $ret;
	}
}
