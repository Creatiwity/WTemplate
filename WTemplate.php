<?php
/**
 * WTemplate.php
 */

require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'WTemplateFile.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'WTemplateParser.php';
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'WTemplateCompiler.php';

/**
 * WTemplate is the template engine used by WityCMS.
 *
 * @package WTemplate
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @version 1.0.0-04-06-2018
 */
class WTemplate {

	/**
	 * @var string Version
	 */
	public static $version = '1.0.0';

	/**
	 * @var string Base directory : the base location of the website
	 */
	private $baseDir;

	/**
	 * @var string Compilation directory: the location to store compiled files.
	 */
	private $compileDir;

	/**
	 * @var array Keys of global variables.
	 */
	private $tpl_vars_global_keys = array();

	/**
	 * @var array Template variables.
	 */
	private $tpl_vars = array();

	/**
	 * @var array Set of variables contexts.
	 */
	public $context = array();

	/**
	 * @var WTemplateCompiler Templates compiler.
	 */
	private $compiler;

	/**
	 * Setups template engine.
	 *
	 * @param string $baseDir    script root directory
	 * @param string $compileDir optional compilation directory
	 * @throws Exception
	 */
	public function __construct($baseDir, $compileDir = '') {
		if (is_dir($baseDir)) {
			$this->baseDir = $baseDir;
		} else {
			throw new Exception("WTemplate::__construct(): Directory \"".$baseDir."\" does not exist.");
		}

		if (!empty($compileDir)) {
			$this->setCompileDir($compileDir);
		}

		$this->compiler = new WTemplateCompiler();
	}

	/**
	 * Sets the compile directory.
	 *
	 * @param string $compileDir Compile directory
	 * @return bool Returns true if the compile directory was sucessfully setup.
	 */
	public function setCompileDir($compileDir) {
		if (is_dir($compileDir)) {
			$this->compileDir = $compileDir;
		} else {
			// Attempt to create compile directory
			if (@mkdir($compileDir, 0777)) {
				$this->compileDir = $compileDir;
				return true;
			} else {
				return false;
			}
		}
	}

	/**
	 * Returns the compile directory used by WTemplate.
	 *
	 * @return string The compile directory
	 */
	public function getCompileDir() {
		return $this->compileDir;
	}

	/**
	 * Changes the variables context in WTemplate.
	 *
	 * If context is changed, next parsed file's variables won't overwrite previous variables.
	 */
	public function pushContext() {
		// Count the number of contexts created
		$index = count($this->context);

		// If no context
		if ($index == 0 && !empty($this->tpl_vars)) {
			// Copy the variables in context #0
			$this->context[0] = $this->tpl_vars;
			$index = 1;
		}

		// Create a new context
		$this->context[$index] = array();

		// Copy global vars
		foreach ($this->tpl_vars_global_keys as $key) {
			$this->context[$index][$key] = $this->tpl_vars[$key];
		}

		$this->tpl_vars = &$this->context[$index];
	}

	/**
	 * Come back to previous context.
	 */
	public function popContext() {
		// Count the number of contexts created
		$index = count($this->context);

		if ($index >= 2) {
			// Use context n-1 and clean the last context
			$this->tpl_vars = &$this->context[$index-2];
			unset($this->context[$index-1]);
		} else if ($index == 1) {
			// Actual context is #0, clean it
			$this->context[0] = array();
		}
	}

	/**
	 * Adds variables whose names are given in $names and their values to the private property $tpl_vars.
	 *
	 * @param array|string $names  Either the name of the variable to assign, or an array(name => value).
	 * @param mixed        $values Value
	 * @param bool         $global Is the variable global?
	 */
	public function assign($names, $values = null, $global = false) {
		if (is_array($names)) {
			foreach ($names as $name => $value) {
				$this->assign($name, $value, $global);
			}
		} else {
			if ($global) {
				// Declare this name as global
				$this->tpl_vars_global_keys[] = $names;

				// For global vars, update value in all contexts
				foreach ($this->context as $id => $vars) {
					$this->context[$id][$names] = $values;
				}
			}

			$this->tpl_vars[$names] = $values;
		}
	}

	/**
	 * Appends given values in a variable, if it exists. Otherwise, initializes it.
	 *
	 * @param string $var   variable name
	 * @param mixed  $value value to append
	 */
	public function append($var, $value) {
		if (isset($this->tpl_vars[$var])) {
			if (!is_array($this->tpl_vars[$var])) {
				settype($this->tpl_vars[$var], 'array');
			}
			$this->tpl_vars[$var][] = $value;
		} else {
			$this->tpl_vars[$var] = array($value);
		}
	}

	/**
	 * Returns the variable value.
	 *
	 * @param string $var Variable's name
	 * @return mixed Variable value or '' if it is not set
	 */
	public function getVar($var) {
		if (isset($this->tpl_vars[$var])) {
			return $this->tpl_vars[$var];
		} else {
			return '';
		}
	}

	/**
	 * Removes template variables.
	 *
	 * @param array|string $vars Variable's name or list of variables's names to clear
	 */
	public function clear($vars) {
		if (is_array($vars)) {
			foreach ($vars as $v) {
				unset($this->tpl_vars[$v]);
			}
		} else {
			unset($this->tpl_vars[$vars]);
		}
	}

	/**
	 * Gets the resulting output of a compiled file without printing anything on screen.
	 *
	 * @param string $href File's href
	 * @return string Output string
	 * @throws Exception
	 */
	public function parse($href) {
		// File init
		$file = new WTemplateFile($href, $this->baseDir, $this->compileDir);

		// Compilation (if needed)
		$code = $file->compile($this->compiler);

		// Buffer
		ob_start();

		// Define a soft handler for undefined variables
		set_error_handler(function($errno, $errstr, $errfile, $errline) {
			echo str_replace(array('Undefined index: ', 'Undefined variable: '), 'WT!', $errstr);
		}, E_NOTICE);

		try { // Critical section
			// Adds the php close balise at the begining because it is a whole php file being evaluated
			$eval_result = eval('?>'.$code);
		} catch (Exception $e) {
			// Just stores the exception into $e to throw it later
		}

		restore_error_handler();

		$buffer = ob_get_contents();
		ob_end_clean();

		// Throw exception if any
		if (!empty($e)) {
			throw $e;
		} else if ($eval_result === false) {
			throw new Exception("WTemplate::parse(): File $href encountered an error during evaluation :".$buffer);
		}

		return $buffer;
	}

	/**
	 * Gets the resulting output of a compiled string without printing anything on screen.
	 *
	 * @param string $string String to compile
	 * @return string Output string
	 * @throws Exception
	 */
	public function parseString($string) {
		// Compile file
		$code = $this->compiler->compileString($string);

		// Buffer
		ob_start();

		// Define a soft handler for undefined variables
		set_error_handler(function($errno, $errstr, $errfile, $errline) {
			echo str_replace(array('Undefined index: ', 'Undefined variable: '), 'WT!', $errstr);
		}, E_NOTICE);

		try { // Critical section
			// Adds the php close balise at the begining because it is a whole php file being evaluated
			$eval_result = eval('?>'.$code);
		} catch (Exception $e) {
			// Just stores the exception into $e to throw it later
		}

		restore_error_handler();

		$buffer = ob_get_contents();
		ob_end_clean();

		// Throw exception if any
		if (!empty($e)) {
			throw $e;
		} else if ($eval_result === false) {
			throw new Exception("WTemplate::parse(): File $href encountered an error during evaluation :".$buffer);
		}

		return $buffer;
	}

	/**
	 * Displays a compiled file on the screen.
	 *
	 * @param string $href File's href
	 */
	public function display($href) {
		// Display parsing result
		echo $this->parse($href);
	}
}

?>
