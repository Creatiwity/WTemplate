<?php
/**
 * WTemplateCompiler.php
 */

/**
 * WTemplateCompiler compiles the nodes used in templates parsed by WTemplate.
 *
 * <p>It replaces a node by its PHP equivalent. To do so, it is composed by several handlers,
 * one for each known node by WTemplate.</p>
 *
 * <p><strong>What is a node?</strong><br />
 * Each <code>{example}</code> is called a "node". In this case, "example" is the name of the node.</p>
 *
 * <p>For a closing node, such as <code>{/example}</code>, the node name is "exemple_close".</p>
 *
 * @package WTemplate
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @version 0.4.0-22-11-2012
 */
class WTemplateCompiler {
	/**
	 * @var array List of nodes opened to check whether they are properly closed
	 */
	private $openNodes = array();

	/**
	 * @var array Some useful information to help the compilation (such as file src, template directory, ...)
	 */
	private $data = array();

	/**
	 * @var array List of all registered external compilers
	 */
	private static $external_compilers = array();

	/**
	 * @var bool Make self::parseVar() compile to local variables
	 */
	public static $use_local_vars = false;

	/**
	 * Registers an external compiler.
	 *
	 * An external compiler is a node handler which belongs to an external class than WTemplateCompiler.
	 * It may be called by WTemplateCompiler whenever the node is found.
	 *
	 * @param string $node_name Node's name to handle (without brackets)
	 * @param string $callback  The compiler to call
	 * @throws Exception
	 */
	public static function registerCompiler($node_name, $callback) {
		if (is_callable($callback)) {
			if (!isset(self::$external_compilers[$node_name])) {
				self::$external_compilers[$node_name] = $callback;
			}
		} else {
			// Prepare excetion message
			if (is_array($callback)) {
				$class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
				$callback = $class.'::'.$callback[1];
			}
			throw new Exception("WTemplateParser::registerCompiler(): callback function \"".$callback."\" given is not callable.");
		}
	}

	/**
	 * Unregisters an external compiler.
	 *
	 * An external compiler is a node handler which belongs to an external class than WTemplateCompiler.
	 * Unregistering a compiler allows you to limit a particular node to be compiled only in a particular context.
	 *
	 * @param string $node_name Node's name to unhandle (without brackets)
	 */
	public static function unregisterCompiler($node_name) {
		unset(self::$external_compilers[$node_name]);
	}

	/**
	 * Compiles an entire string containing nodes.
	 * This method is called when WTemplate is asked to parse a file.
	 *
	 * @param string    $string The string that will be compiled
	 * @param array     $data   Some extra data to use in compilation handlers (such as file's href)
	 * @return string The compiled file
	 * @throws Exception
	 */
	public function compileString($string, array $data = array()) {
		// clear open tags
		$this->openNodes = array();
		$this->data = $data;

		$code = WTemplateParser::replaceNodes($string, array($this, 'compileNode'));

		if (!empty($this->openNodes)) {
			throw new Exception("WTemplateCompiler::compileString(): some tags were not properly closed (".implode(', ', $this->openNodes).").");
		}

		$this->data = array();

		// Replace XML tag to prevent short open tag conflict
		$code = str_replace("<?xml", "<?php echo '<?xml'; ?>", $code);

		return $code;
	}

	/**
	 * Compiles a single node.
	 *
	 * @param string $original_node  Node that will be compiled without wrapping brackets {}
	 * @param bool   $inner_node     Boolean to know if it is an inner-node being compiled
	 * @return string The compiled node
	 * @throws Exception
	 */
	public function compileNode($original_node, $inner_node = false) {
		$node = trim($original_node);
		if (empty($node)) {
			return "";
		}
		$output = "";

		// Variable display
		if (strpos($node, '$') === 0) {
			if ($inner_node) {
				// Inner variables will be treated by compilers
				$output = '{'.$original_node.'}';
			} else {
				$output = $this->compile_var($node);
			}
		}
		// Closing tag
		else if (strpos($node, '/') === 0) {
			$node = substr($node, 1);
			$node_name = $node.'_close';
			$handler = 'compile_'.$node_name;

			// Check last open tag
			if (array_pop($this->openNodes) != $node) {
				throw new Exception("WTemplateCompiler::compileNode(): mismatched node {".$node."} opening tag.");
			}

			// Call handler
			if (method_exists('WTemplateCompiler', $handler)) {
				$output = $this->$handler();
			} else if (isset(self::$external_compilers[$node_name])) {
				$output = call_user_func(self::$external_compilers[$node_name]);
			}
		}
		// Opening tag
		else {
			// Get begining tag name : {"name" ...}
			$matches = null;
			preg_match('#^([a-zA-Z0-9_]+)#', $node, $matches);

			if (empty($matches)) {
				throw new Exception("WTemplateCompiler::compileNode(): invalid node \"{".$node."}\".");
			}

			$node_name = $matches[0];
			$handler = 'compile_'.$node_name;

			// Remove node name to get following string
			$args = trim(substr($node, strlen($node_name)));

			if (method_exists('WTemplateCompiler', $handler)) {
				// Check whether it is not an open only node
				if (method_exists('WTemplateCompiler', $handler.'_close')) {
					// Add item in open nodes list
					$this->openNodes[] = $node_name;
				}

				// Call handler
				$output = $this->$handler($args);
			} else if (isset(self::$external_compilers[$node_name])) {
				if (isset(self::$external_compilers[$node_name.'_close'])) {
					// Add item in open nodes list
					$this->openNodes[] = $node_name;
				}

				$output = call_user_func(self::$external_compilers[$node_name], $args);
			} else {
				throw new Exception("WTemplateCompiler::compileNode(): no compiler handler found for node {".$node."}.");
			}
		}

		return $output;
	}

	/**
	 * Parses a variable node into PHP code.
	 * Vars should have this format: {$var.index1.index2...|function1|function2...}
	 *
	 * Nesting vars can be used, such as: {$var1.{$var2.x}}
	 *
	 * @param string $string A string that will be compiled
	 * @return string The compiled string
	 */
	public static function parseVar($string) {
		if (strpos($string, '$') !== 0) {
			return '';
		} else if (strpos($string, '$this->') === 0) {
			return $string;
		}

		// Remove begining '$' char
		$string = substr($string, 1);

		// Replace nested variables
		if (strpos($string, '{') !== false) {
			$string = self::replaceVars($string);
		}

		// Get function list
		$functions = explode('|', $string);

		$var_string = array_shift($functions);

		$levels = explode('.', $var_string);

		// In {block}, local variables must be used directly
		if (self::$use_local_vars) {
			$return = '$'.array_shift($levels);
		} else {
			$return = '$this->tpl_vars';
		}

		foreach ($levels as $s) {
			$s = trim($s);
			if (strpos($s, '$') === 0 || strpos($s, '(') !== false) {
				$return .= '['.$s.']';
			} else {
				$return .= "['".$s."']";
			}
		}

		// Functions to apply on the variable
		foreach ($functions as $f) {
			$f = trim($f);
			switch ($f) {
				// Add custom functions here:
				// case 'custom': break;

				default:
					if (function_exists($f)) {
						$return = $f.'('.$return.')';
					}
					break;
			}
		}

		return $return;
	}

	/**
	 * Replaces all variable nodes in a given string by their PHP values.
	 * For each node, it calls WTemplateCompiler::parseVar().
	 *
	 * @see WTemplateParser::replaceNodes()
	 * @param string $string A string in which variable names will be replaced by their values
	 * @return string Compiled string with PHP's variables
	 */
	public static function replaceVars($string) {
		return WTemplateParser::replaceNodes($string, array('WTemplateCompiler', 'parseVar'));
	}

	/**
	 * Compiles a variable displaying it.
	 *
	 * <code>{$array.index1.index2...|func1|func2...}</code>
	 *
	 * @param string $args A string of variables that will be compiled
	 * @return string The compiled variables
	 */
	public function compile_var($args) {
		if (!empty($args)) {
			return '<?php echo '.$this->parseVar($args).'; ?>';
		}

		return '';
	}

	/**
	 * Compiles {include} node to include sub template files.
	 *
	 * <code>{include file_href}</code>
	 *
	 * @param string $file The file to include
	 * @return string The php code displying the compiled file (only variables are compiled)
	 */
	public function compile_include($file) {
		if (empty($file)) {
			return '';
		}

		// {$var} are replaced by ".{$var}." so that they can concat with other strings
		$file = str_replace(array('"', "'"), '', $file);
		$file = str_replace(array('{', '}'), array('".{', '}."'), $file);

		if (!empty($this->data['dir'])) {
			$dir = str_replace('\\', '/', $this->data['dir']);
			$file = str_replace('./', $dir.'/', $file);
			$file = str_replace('../', dirname($dir).'/', $file);
		}

		return '<?php $this->display("'.$file.'"); ?>';
	}

	/**
	 * Compiles {if condition}.
	 *
	 * @param string $args Arguments within the {if} node
	 * @return string The compiled code
	 */
	public function compile_if($args) {
		// Replace variables in condition
		$cond = $this->replaceVars($args);

		return '<?php if ('.$cond.'): ?>';
	}

	/**
	 * Compiles {else}.
	 *
	 * @return string The compiled code
	 */
	public function compile_else() {
		return '<?php else: ?>';
	}

	/**
	 * Compiles {elseif}.
	 *
	 * @param string $args Arguments within the {elseif} node
	 * @return string The compiled code
	 */
	public function compile_elseif($args) {
		return str_replace('if', 'elseif', $this->compile_if($args));
	}

	/**
	 * Compiles {/if}.
	 *
	 * @return string The compiled code
	 */
	public function compile_if_close() {
		return '<?php endif; ?>';
	}

	/**
	 * @var int Counts the number of {for} in order to make {empty} work properly
	 */
	private $for_count = 0;

	/**
	 * Compiles {for [$key, ]$value in $array}.
	 *
	 * @param string $args Arguments of the {for} block
	 * @return string The compiled code
	 */
	public function compile_for($args) {
		$matches = array();
		// RegEx string to search "$key, $value in $array" substring
		if (!preg_match('#^(\{?(\$[a-zA-Z0-9_]+)\}?,\s*)?\{?(\$[a-zA-Z0-9_]+)\}?\s+in\s+(.+)$#U', $args, $matches)) {
			throw new Exception("WTemplateCompiler::compile_for(): Wrong syntax for node {for ".$args."}.");
		}

		if ($this->for_count < 0) {
			$this->for_count = 0;
		}
		$this->for_count++;

		list(,, $key, $value, $array) = $matches;

		if ($array[0] == '$') {
			$array = $this->parseVar($array);
		} else {
			$array = $this->replaceVars($array);
		}
		
		$value = $this->parseVar($value);

		$s = "<?php \$hidden_counter".$this->for_count." = 0;\n";
		if (empty($key)) {
			$s .= "foreach((array) ".$array." as ".$value."):\n";
		} else {
			$key = $this->parseVar($key);
			$s .= "foreach((array) ".$array." as ".$key." => ".$value."):\n";
		}

		return $s."	\$hidden_counter".$this->for_count."++; ?>";
	}

	/**
	 * Compiles {/for}.
	 *
	 * @return string The compiled code
	 */
	public function compile_for_close() {
		$this->for_count--;

		return '<?php endforeach; ?>';
	}

	/**
	 * Compiles {empty}.
	 *
	 * {empty} is to use right after a {for} node.
	 * Its content is displayed when the array iterated in the loop is empty.
	 *
	 * @return string The compiled code
	 */
	public function compile_empty() {
		return "<?php if (isset(\$hidden_counter".($this->for_count+1).") && intval(\$hidden_counter".($this->for_count+1).") == 0): ?>";
	}

	/**
	 * Compiles {/empty}.
	 *
	 * @return string The compiled code
	 */
	public function compile_empty_close() {
		return "<?php endif; ?>";
	}

	/**
	 * Compiles an assignment.
	 *
	 * <code>{set $a = 5}
	 * {set {$a} = {$a} + 1}</code>
	 *
	 * @param string $args An assignment <code>var = value</code>
	 * @return string The compiled code
	 */
	public function compile_set($args) {
		$first_equal_pos = strpos($args, '=');
		if ($first_equal_pos !== false) {
			$var   = trim(substr($args, 0, $first_equal_pos));
			$value = trim(substr($args, $first_equal_pos+1));

			if ($var[0] == '$') {
				$var = $this->parseVar($var);
			} else {
				$var = $this->replaceVars($var);
			}

			$value = $this->replaceVars($value);

			return "<?php ".$var." = ".$value."; ?>";
		}

		return '';
	}

	/**
	 * Compiles {define_block test $a $b}.
	 * 
	 * A block is equivalent to a function.
	 * 
	 * <code>
	 *   {define_block test $a}
	 *     a equals to {$a}
	 *   {/define_block}
	 *   {block test 5} // prints "a equals to 5"
	 * </code>
	 * 
	 * @param string $args A block name + arguments
	 * @return string Beginning of a PHP function code
	 */
	public function compile_define_block($args) {
		self::$use_local_vars = true;

		$array = preg_split('#\s+#', $args);

		$name = array_shift($array);

		$args = implode(', ', $array);

		return '<?php function '.$name.'('.$args.') { ?>';
	}

	/**
	 * Compiles {/define_block}
	 * 
	 * @return string End of code for define_block opening node
	 */
	public function compile_define_block_close() {
		self::$use_local_vars = false;

		return '<?php } ?>';
	}

	/**
	 * Compiles {block test "str1" "str2"}.
	 * 
	 * @param string $args Name of the block to trigger + arguments
	 * @return string Compiled code to trigger the PHP function
	 */
	public function compile_block($args) {
		$args = $this->replaceVars($args);

		$array = preg_split('#\s+#', $args);

		$name = array_shift($array);

		$args = implode(', ', $array);

		return '<?php '.$name.'('.$args.'); ?>';
	}

	/**
	 * Compiles {range $i = 0..10}.
	 * 
	 * The range node is equivalent to a for loop.
	 * 
	 * <code>
	 *   {set $start = 0}
	 *   {set $step = 2}
	 *   {range $i = {$start}.{$step}.10}{$i}{/range} // prints "0 2 4 6 8 10"
	 * </code>
	 * 
	 * @param string $args The counter name + limit of the range
	 * @return string The compiled code
	 */
	public function compile_range($args) {
		$matches = array();
		// RegEx string to search "$i = ($start|0).($step|).($end|10)"
		if (!preg_match('#^\{?(\$[a-zA-Z0-9_]+)\}?\s+=\s+(\{?(\$[a-zA-Z0-9_]+)\}?|[0-9]+)\.(\{?(\$[a-zA-Z0-9_]+)\}?|[0-9]+)?\.(\{?(\$[a-zA-Z0-9_]+)\}?|[0-9]+)\s*$#U', $args, $matches)) {
			throw new Exception("WTemplateCompiler::compile_for(): Wrong syntax for node {for ".$args."}.");
		}

		list(, $counter, $start, $start_var, $step, $step_var, $end, $end_var) = $matches;

		if (empty($step)) {
			$step = 1;
		}

		$counter = $this->parseVar($counter);

		if (!empty($start_var)) {
			$start = $this->parseVar($start_var);
		}
		if (!empty($step_var)) {
			$step = $this->parseVar($step_var);
		}
		if (!empty($end_var)) {
			$end = $this->parseVar($end_var);
		}

		return '<?php for ('.$counter.' = '.$start.'; '.$counter.' <= '.$end.'; '.$counter.' += '.$step.'): ?>';
	}

	/**
	 * Compiles {/range}
	 * 
	 * @return string Closing code for {range} node
	 */
	public function compile_range_close() {
		return '<?php endfor; ?>';
	}
}

?>
