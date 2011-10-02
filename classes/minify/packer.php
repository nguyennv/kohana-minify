<?php defined('SYSPATH') or die('No direct script access.');
/* 9 April 2008. version 1.1
 * 
 * This is the php version of the Dean Edwards JavaScript's Packer,
 * Based on :
 * 
 * Minify_Packer_Parse, version 1.0.2 (2005-08-19) Copyright 2005, Dean Edwards
 * a multi-pattern parser.
 * KNOWN BUG: erroneous behavior when using escape_char with a replacement
 * value that is a function
 * 
 * packer, version 2.0.2 (2005-08-19) Copyright 2004-2005, Dean Edwards
 * 
 * License: http://creativecommons.org/licenses/LGPL/2.1/
 * 
 * Ported to PHP by Nicolas Martin.
 * 
 * ----------------------------------------------------------------------
 * changelog:
 * 1.1 : correct a bug, '\0' packed then unpacked becomes '\'.
 * ----------------------------------------------------------------------
 * 
 * examples of usage :
 * $myPacker = new Minify_Packer($script, 62, TRUE, FALSE);
 * $packed = $myPacker->pack();
 * 
 * or
 * 
 * $myPacker = new Minify_Packer($script, 'Normal', TRUE, FALSE);
 * $packed = $myPacker->pack();
 * 
 * or (default values)
 * 
 * $myPacker = new Minify_Packer($script);
 * $packed = $myPacker->pack();
 * 
 * 
 * params of the constructor :
 * $script:	   the JavaScript to pack, string.
 * $encoding:	 level of encoding, int or string :
 *				0,10,62,95 or 'None', 'Numeric', 'Normal', 'High ASCII'.
 *				default: 62.
 * $fastDecode:   include the fast decoder in the packed result, boolean.
 *				default : TRUE.
 * $specialChars: if you are flagged your private and local variables
 *				in the script, boolean.
 *				default: FALSE.
 * 
 * The pack() method return the compressed JavasScript, as a string.
 * 
 * see http://dean.edwards.name/packer/usage/ for more information.
 * 
 * Notes :
 * # need PHP 5 . Tested with PHP 5.1.2, 5.1.3, 5.1.4, 5.2.3
 * 
 * # The packed result may be different than with the Dean Edwards
 *   version, but with the same length. The reason is that the PHP
 *   function usort to sort array don't necessarily preserve the
 *   original order of two equal member. The Javascript sort function
 *   in fact preserve this order (but that's not require by the
 *   ECMAScript standard). So the encoded keywords order can be
 *   different in the two results.
 * 
 * # Be careful with the 'High ASCII' Level encoding if you use
 *   UTF-8 in your files... 
 */

class Minify_Packer {
	// constants
	const IGNORE = '$1';

	// validate parameters
	private $_script = '';
	private $_encoding = 62;
	private $_fast_decode = TRUE;
	private $_special_chars = FALSE;

	private $_LITERAL_ENCODING = array(
		'None' => 0,
		'Numeric' => 10,
		'Normal' => 62,
		'High ASCII' => 95
	);

	public function __construct($script, $encoding = 62, $fast_decode = TRUE, $special_chars = FALSE)
	{
		$this->_script = $script . "\n";
		if (array_key_exists($encoding, $this->_LITERAL_ENCODING))
			$encoding = $this->_LITERAL_ENCODING[$encoding];
		$this->_encoding = min((int)$encoding, 95);
		$this->_fast_decode = (bool) $fast_decode;	
		$this->_special_chars = (bool) $special_chars;
	}

	public function pack()
	{
		$this->_add_parser('_basic_compression');
		if ($this->_special_chars)
			$this->_add_parser('_encode_special_chars');
		if ($this->_encoding)
			$this->_add_parser('_encode_keywords');

		// go!
		return $this->_pack($this->_script);
	}

	// apply all parsing routines
	private function _pack($script)
	{
		for ($i = 0; isset($this->_parsers[$i]); $i++)
		{
			$script = call_user_func(array(&$this,$this->_parsers[$i]), $script);
		}
		return $script;
	}

	// keep a list of parsing functions, they'll be executed all at once
	private $_parsers = array();

	private function _add_parser($parser)
	{
		$this->_parsers[] = $parser;
	}

	// zero encoding - just removal of white space and comments
	private function _basic_compression($script)
	{
		$parser = new Minify_Packer_Parse();
		// make safe
		$parser->escape_char = '\\';
		// protect strings
		$parser->add('/\'[^\'\\n\\r]*\'/', self::IGNORE);
		$parser->add('/"[^"\\n\\r]*"/', self::IGNORE);
		// remove comments
		$parser->add('/\\/\\/[^\\n\\r]*[\\n\\r]/', ' ');
		$parser->add('/\\/\\*[^*]*\\*+([^\\/][^*]*\\*+)*\\//', ' ');
		// protect regular expressions
		$parser->add('/\\s+(\\/[^\\/\\n\\r\\*][^\\/\\n\\r]*\\/g?i?)/', '$2'); // IGNORE
		$parser->add('/[^\\w\\x24\\/\'"*)\\?:]\\/[^\\/\\n\\r\\*][^\\/\\n\\r]*\\/g?i?/', self::IGNORE);
		// remove: ;;; do_something();
		if ($this->_special_chars) $parser->add('/;;;[^\\n\\r]+[\\n\\r]/');
		// remove redundant semi-colons
		$parser->add('/\\(;;\\)/', self::IGNORE); // protect for (;;) loops
		$parser->add('/;+\\s*([};])/', '$2');
		// apply the above
		$script = $parser->exec($script);

		// remove white-space
		$parser->add('/(\\b|\\x24)\\s+(\\b|\\x24)/', '$2 $3');
		$parser->add('/([+\\-])\\s+([+\\-])/', '$2 $3');
		$parser->add('/\\s+/', '');
		// done
		return $parser->exec($script);
	}

	private function _encode_special_chars($script)
	{
		$parser = new Minify_Packer_Parse();
		// replace: $name -> n, $$name -> na
		$parser->add('/((\\x24+)([a-zA-Z$_]+))(\\d*)/',
					 array('fn' => '_replace_name')
		);
		// replace: _name -> _0, double-underscore (__name) is ignored
		$regexp = '/\\b_[A-Za-z\\d]\\w*/';
		// build the word list
		$keywords = $this->_analyze($script, $regexp, '_encode_private');
		// quick ref
		$encoded = $keywords['encoded'];

		$parser->add($regexp,
			array(
				'fn' => '_replace_encoded',
				'data' => $encoded
			)
		);
		return $parser->exec($script);
	}

	private function _encode_keywords($script)
	{
		// escape high-ascii values already in the script (i.e. in strings)
		if ($this->_encoding > 62)
			$script = $this->_escape95($script);
		// create the parser
		$parser = new Minify_Packer_Parse();
		$encode = $this->_get_encoder($this->_encoding);
		// for high-ascii, don't encode single character low-ascii
		$regexp = ($this->_encoding > 62) ? '/\\w\\w+/' : '/\\w+/';
		// build the word list
		$keywords = $this->_analyze($script, $regexp, $encode);
		$encoded = $keywords['encoded'];

		// encode
		$parser->add($regexp,
			array(
				'fn' => '_replace_encoded',
				'data' => $encoded
			)
		);
		if (empty($script))
			return $script;
		else
		{
			//$res = $parser->exec($script);
			//$res = $this->_bootstrap($res, $keywords);
			//return $res;
			return $this->_bootstrap($parser->exec($script), $keywords);
		}
	}

	private function _analyze($script, $regexp, $encode)
	{
		// analyse
		// retreive all words in the script
		$all = array();
		preg_match_all($regexp, $script, $all);
		$_sorted = array(); // list of words sorted by frequency
		$_encoded = array(); // dictionary of word->encoding
		$_protected = array(); // instances of "protected" words
		$all = $all[0]; // simulate the javascript comportement of global match
		if (!empty($all))
		{
			$unsorted = array(); // same list, not sorted
			$protected = array(); // "protected" words (dictionary of word->"word")
			$value = array(); // dictionary of char_code->encoding (eg. 256->ff)
			$this->_count = array(); // word->count
			$i = count($all); $j = 0; //$word = null;
			// count the occurrences - used for sorting later
			do
			{
				--$i;
				$word = '$' . $all[$i];
				if (!isset($this->_count[$word]))
				{
					$this->_count[$word] = 0;
					$unsorted[$j] = $word;
					// make a dictionary of all of the protected words in this script
					//  these are words that might be mistaken for encoding
					//if (is_string($encode) && method_exists($this, $encode))
					$values[$j] = call_user_func(array(&$this, $encode), $j);
					$protected['$' . $values[$j]] = $j++;
				}
				// increment the word counter
				$this->_count[$word]++;
			} while ($i > 0);
			// prepare to sort the word list, first we must protect
			//  words that are also used as codes. we assign them a code
			//  equivalent to the word itself.
			// e.g. if "do" falls within our encoding range
			//	  then we store keywords["do"] = "do";
			// this avoids problems when decoding
			$i = count($unsorted);
			do
			{
				$word = $unsorted[--$i];
				if (isset($protected[$word]) /*!= null*/) {
					$_sorted[$protected[$word]] = substr($word, 1);
					$_protected[$protected[$word]] = TRUE;
					$this->_count[$word] = 0;
				}
			} while ($i);

			// sort the words by frequency
			// Note: the javascript and php version of sort can be different :
			// in php manual, usort :
			// " If two members compare as equal,
			// their order in the sorted array is undefined."
			// so the final packed script is different of the Dean's javascript version
			// but equivalent.
			// the ECMAscript standard does not guarantee this behaviour,
			// and thus not all browsers (e.g. Mozilla versions dating back to at
			// least 2003) respect this. 
			usort($unsorted, array(&$this, '_sort_words'));
			$j = 0;
			// because there are "protected" words in the list
			//  we must add the sorted words around them
			do
			{
				if (!isset($_sorted[$i]))
					$_sorted[$i] = substr($unsorted[$j++], 1);
				$_encoded[$_sorted[$i]] = $values[$i];
			} while (++$i < count($unsorted));
		}
		return array(
			'sorted'  => $_sorted,
			'encoded' => $_encoded,
			'protected' => $_protected);
	}

	private $_count = array();

	private function _sort_words($match1, $match2)
	{
		return $this->_count[$match2] - $this->_count[$match1];
	}

	// build the boot function used for loading and decoding
	private function _bootstrap($packed, $keywords)
	{
		$_ENCODE = $this->_safe_reg_exp('$encode\\($count\\)');

		// $packed: the packed script
		$packed = "'" . $this->_escape($packed) . "'";

		// $ascii: base for encoding
		$ascii = min(count($keywords['sorted']), $this->_encoding);
		if ($ascii == 0) $ascii = 1;

		// $count: number of words contained in the script
		$count = count($keywords['sorted']);

		// $keywords: list of words contained in the script
		foreach ($keywords['protected'] as $i=>$value)
		{
			$keywords['sorted'][$i] = '';
		}
		// convert from a string to an array
		ksort($keywords['sorted']);
		$keywords = "'" . implode('|',$keywords['sorted']) . "'.split('|')";

		$encode = ($this->_encoding > 62) ? '_encode95' : $this->_get_encoder($ascii);
		$encode = $this->_get_js_function($encode);
		$encode = preg_replace('/_encoding/','$ascii', $encode);
		$encode = preg_replace('/arguments\\.callee/','$encode', $encode);
		$inline = '\\$count' . ($ascii > 10 ? '.toString(\\$ascii)' : '');

		// $decode: code snippet to speed up decoding
		if ($this->_fast_decode)
		{
			// create the decoder
			$decode = $this->_get_js_function('_decodeBody');
			if ($this->_encoding > 62)
				$decode = preg_replace('/\\\\w/', '[\\xa1-\\xff]', $decode);
			// perform the encoding inline for lower ascii values
			elseif ($ascii < 36)
				$decode = preg_replace($_ENCODE, $inline, $decode);
			// special case: when $count==0 there are no keywords. I want to keep
			//  the basic shape of the unpacking funcion so i'll frig the code...
			if ($count == 0)
				$decode = preg_replace($this->_safe_reg_exp('($count)\\s*=\\s*1'), '$1=0', $decode, 1);
		}

		// boot function
		$unpack = $this->_get_js_function('_unpack');
		if ($this->_fast_decode)
		{
			// insert the decoder
			$this->buffer = $decode;
			$unpack = preg_replace_callback('/\\{/', array(&$this, '_insert_fast_decode'), $unpack, 1);
		}
		$unpack = preg_replace('/"/', "'", $unpack);
		if ($this->_encoding > 62) // high-ascii
		{
			// get rid of the word-boundaries for regexp matches
			$unpack = preg_replace('/\'\\\\\\\\b\'\s*\\+|\\+\s*\'\\\\\\\\b\'/', '', $unpack);
		}
		if ($ascii > 36 || $this->_encoding > 62 || $this->_fast_decode)
		{
			// insert the encode function
			$this->buffer = $encode;
			$unpack = preg_replace_callback('/\\{/', array(&$this, '_insert_fast_encode'), $unpack, 1);
		}
		else
		{
			// perform the encoding inline
			$unpack = preg_replace($_ENCODE, $inline, $unpack);
		}
		// pack the boot function too
		$unpack_packer = new Minify_Packer($unpack, 0, FALSE, TRUE);
		$unpack = $unpack_packer->pack();

		// arguments
		$params = array($packed, $ascii, $count, $keywords);
		if ($this->_fast_decode)
		{
			$params[] = 0;
			$params[] = '{}';
		}
		$params = implode(',', $params);

		// the whole thing
		return 'eval(' . $unpack . '(' . $params . "))\n";
	}

	private $buffer;

	private function _insert_fast_decode($match)
	{
		return '{' . $this->buffer . ';';
	}

	private function _insert_fast_encode($match)
	{
		return '{$encode=' . $this->buffer . ';';
	}

	// mmm.. ..which one do i need ??
	private function _get_encoder($ascii)
	{
		return $ascii > 10 ? $ascii > 36 ? $ascii > 62 ?
			   '_encode95' : '_encode62' : '_encode36' : '_encode10';
	}

	// zero encoding
	// characters: 0123456789
	private function _encode10($char_code)
	{
		return $char_code;
	}

	// inherent base36 support
	// characters: 0123456789abcdefghijklmnopqrstuvwxyz
	private function _encode36($char_code)
	{
		return base_convert($char_code, 10, 36);
	}

	// hitch a ride on base36 and add the upper case alpha characters
	// characters: 0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ
	private function _encode62($char_code)
	{
		$res = '';
		if ($char_code >= $this->_encoding)
		{
			$res = $this->_encode62((int)($char_code / $this->_encoding));
		}
		$char_code = $char_code % $this->_encoding;
		
		if ($char_code > 35)
			return $res . chr($char_code + 29);
		else
			return $res . base_convert($char_code, 10, 36);
	}

	// use high-ascii values
	// characters: ¡¢£¤¥¦§¨©ª«¬­®¯°±²³´µ¶·¸¹º»¼½¾¿ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõö÷øùúûüýþ
	private function _encode95($char_code)
	{
		$res = '';
		if ($char_code >= $this->_encoding)
			$res = $this->_encode95($char_code / $this->_encoding);
		
		return $res . chr(($char_code % $this->_encoding) + 161);
	}

	private function _safe_reg_exp($string)
	{
		return '/'.preg_replace('/\$/', '\\\$', $string).'/';
	}

	private function _encode_private($char_code)
	{
		return "_" . $char_code;
	}

	// protect characters used by the parser
	private function _escape($script)
	{
		return preg_replace('/([\\\\\'])/', '\\\$1', $script);
	}

	// protect high-ascii characters already in the script
	private function _escape95($script)
	{
		return preg_replace_callback(
			'/[\\xa1-\\xff]/',
			array(&$this, '_escape_95_bis'),
			$script
		);
	}

	private function _escape_95_bis($match)
	{
		return '\x'.((string)dechex(ord($match)));
	}

	private function _get_js_function($aName)
	{
		if (defined('self::JSFUNCTION'.$aName))
			return constant('self::JSFUNCTION'.$aName);
		else 
			return '';
	}

	// JavaScript Functions used.
	// Note : In Dean's version, these functions are converted
	// with 'String(aFunctionName);'.
	// This internal conversion complete the original code, ex :
	// 'while (aBool) anAction();' is converted to
	// 'while (aBool) { anAction(); }'.
	// The JavaScript functions below are corrected.
	
	// unpacking function - this is the boot strap function
	//  data extracted from this packing routine is passed to
	//  this function when decoded in the target
	// NOTE ! : without the ';' final.
	const JSFUNCTION_unpack =
'function($packed, $ascii, $count, $keywords, $encode, $decode) {
	while ($count--) {
		if ($keywords[$count]) {
			$packed = $packed.replace(new RegExp(\'\\\\b\' + $encode($count) + \'\\\\b\', \'g\'), $keywords[$count]);
		}
	}
	return $packed;
}';
/*
'function($packed, $ascii, $count, $keywords, $encode, $decode) {
	while ($count--)
		if ($keywords[$count])
			$packed = $packed.replace(new RegExp(\'\\\\b\' + $encode($count) + \'\\\\b\', \'g\'), $keywords[$count]);
	return $packed;
}';
*/

	// code-snippet inserted into the unpacker to speed up decoding
	const JSFUNCTION_decodeBody =
//_decode = function() {
// does the browser support String.replace where the
//  replacement value is a function?
'	if (!\'\'.replace(/^/, String)) {
		// decode all the values we need
		while ($count--) {
			$decode[$encode($count)] = $keywords[$count] || $encode($count);
		}
		// global replacement function
		$keywords = [function ($encoded) {return $decode[$encoded]}];
		// generic match
		$encode = function () {return \'\\\\w+\'};
		// reset the loop counter -  we are now doing a global replace
		$count = 1;
	}
';
//};
/*
'	if (!\'\'.replace(/^/, String)) {
		// decode all the values we need
		while ($count--) $decode[$encode($count)] = $keywords[$count] || $encode($count);
		// global replacement function
		$keywords = [function ($encoded) {return $decode[$encoded]}];
		// generic match
		$encode = function () {return\'\\\\w+\'};
		// reset the loop counter -  we are now doing a global replace
		$count = 1;
	}';
*/

	 // zero encoding
	 // characters: 0123456789
	 const JSFUNCTION_encode10 =
'function($char_code) {
	return $char_code;
}';//;';

	 // inherent base36 support
	 // characters: 0123456789abcdefghijklmnopqrstuvwxyz
	 const JSFUNCTION_encode36 =
'function($char_code) {
	return $char_code.toString(36);
}';//;';

	// hitch a ride on base36 and add the upper case alpha characters
	// characters: 0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ
	const JSFUNCTION_encode62 =
'function($char_code) {
	return ($char_code < _encoding ? \'\' : arguments.callee(parseInt($char_code / _encoding))) +
	(($char_code = $char_code % _encoding) > 35 ? String.fromCharCode($char_code + 29) : $char_code.toString(36));
}';
	
	// use high-ascii values
	// characters: ¡¢£¤¥¦§¨©ª«¬­®¯°±²³´µ¶·¸¹º»¼½¾¿ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõö÷øùúûüýþ
	const JSFUNCTION_encode95 =
'function($char_code) {
	return ($char_code < _encoding ? \'\' : arguments.callee($char_code / _encoding)) +
		String.fromCharCode($char_code % _encoding + 161);
}'; 

}
