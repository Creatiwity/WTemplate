<?php 
/**
 * WTemplateParser.php
 */

/**
 * WTemplateParser is the parser part of WTemplate.
 *
 * @package WTemplate
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @version 0.4.0-22-11-2012
 */
class WTemplateParser {
	/**
	 * Replaces all nodes found in $string by the callback result.
	 * 
	 * If the char '{' is backslashed or directly followed by a carriage return, it will be ignored.
	 * 
	 * @param string  $string   A string to parse.
	 * @param string  $callback The callback to call to replace the node.
	 * @return string The parsed string on which all callback's results are in it.
	 * @throws Exception
	 */
	public static function replaceNodes($string, $callback) {
		$length = strlen($string);
		$level = 0;
		$code = ""; // $code stocks the entire code compiled
		$tmp_array = array(0 => ''); // $tmp_array stocks the node (and sub-nodes) currently being read
		$last_char = '';
		$comment = false;
		
		if (!is_callable($callback)) {
			if (is_array($callback)) {
				$class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
				$callback = $class.'::'.$callback[1];
			}
			throw new Exception("WTemplateParser::replaceNodes(): callback function \"".$callback."\" given is not callable.");
		}
		
		for ($i = 0; $i < strlen($string); $i++) {
			// Get next char
			$char = $string[$i];
			
			switch ($char) {
				case '\\': // backslash
					// backslash in a node are always saved since it is up to self::findAllNodes to manage them
					if ($level > 0) {
						$tmp .= '\\';
					} else {
						if ($last_char == '\\') {
							$code .= '\\';
							
							// $char set to null in order to set $last_char to null because the '\' char has been canceled by the previous '\'
							$char = '';
						}
					}
					break;
				
				case '%': // comment node
					if ($level > 0 && $last_char == '{') {
						$comment = true;
					}
					break;
				
				case '{':
					if (!$comment) {
						// Check whether { is backslashed
						// List of authorized chars to start a node (alphanum, / for closing nodes and $ for var displaying nodes
						if ($i < $length-1 && preg_match('#[a-zA-Z0-9/$%]#', $string[$i+1]) && $last_char != '\\') {
							$level++;
							
							// Create a new level in the temporary array
							$tmp_array[$level] = '';
						} else {
							// Are we in a node?
							if ($level > 0) {
								$tmp_array[$level] .= '{';
							} else {
								$code .= '{';
							}
						}
					}
					break;
				
				case '}':
					if ($level > 0) {
						if (!$comment) {
							// Check whether } is backslashed
							if ($last_char != '\\') {
								$level--;
								
								// Immediately compile superior level
								$tmp_array[$level] .= call_user_func($callback, $tmp_array[$level+1], $level > 0);
								
								// Delete superior level
								unset($tmp_array[$level+1]);
							} else {
								// Add the closing bracket
								$tmp_array[$level] .= '}';
							}
							
							// We arrived at the end of the node
							if ($level == 0) {
								// Add the compile node to the global string $code
								$code .= $tmp_array[0];
								// Clean temporary array
								$tmp_array = array(0 => '');
							}
						} else if ($last_char == '%') {
							$comment = false;
							$level--;
							$tmp_array = array(0 => '');
						}
					} else {
						$code .= '}';
					}
					break;
				
				default:
					if ($char == "\n" && $level > 0 && !$comment) {
						throw new Exception("WTemplateParser::replaceNodes(): found illegal carriage return character in a node (".$tmp_array[$level].").");
					}
					
					if ($level > 0) {
						// We are in a node. Special chars may be used
						// so add them back
						if ($last_char == '\\') {
							$tmp_array[$level] .= '\\';
						} else if ($last_char == '%') {
							$tmp_array[$level] .= '%';
						}
						$tmp_array[$level] .= $char;
					} else {
						if ($last_char == '\\') {
							$code .= '\\';
						} else if ($last_char == '%') {
							$code .= '%';
						}
						$code .= $char;
					}
					break;
			}
			
			$last_char = $char;
		}
		
		return $code;
	}
}

?>
