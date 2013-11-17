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
 * @version 0.4.0-26-10-2012
 */
class WTemplate {
	/**
	 * @var string Compilation directory: where to place compiled files
	 */
	private $compileDir;
	
	/**
	 * @var array Template variables
	 */
	private $tpl_vars = array();
	
	/**
	 * @var Set of variables contexts
	 */
	public $context = array();
	
	/**
	 * @var WTemplateCompiler Template compilator
	 */
	private $compiler;
	
	/**
	 * Setup template engine
	 * 
	 * @param string $baseDir script root directory
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
	 * Sets the compile directory
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
	 * Changes the variables context in WTemplate
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
		$this->tpl_vars = &$this->context[$index];
	}
	
	/**
	 * Come back to previous context
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
	 * Adds variables whose names are in names and their values to the private property $tpl_vars
	 * 
	 * @param array|string $names
	 * @param array|mixed $values
	 */
	public function assign($names, $values = null, $global = false) {
		if (is_array($names)) {
			$this->tpl_vars = array_merge($this->tpl_vars, $names);
		} else {
			if ($global && count($this->context) != 0) { // if no context, use directly $tpl_vars
				// For global vars, update value in all contexts
				foreach ($this->context as $id => $vars) {
					$this->context[$id][$names] = $values;
				}
			} else {
				$this->tpl_vars[$names] = $values;
			}
		}
	}
	
	/**
	 * Adds values in a variable if exists
	 * 
	 * @param string    $var    variable name
	 * @param mixed     $value  value to append
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
	 * Returns the variable value
	 * 
	 * @param string $var variable name
	 * @return mixed variable value or '' if it is not set
	 */
	public function getVar($var) {
		if (isset($this->tpl_vars[$var])) {
			return $this->tpl_vars[$var];
		} else {
			return '';
		}
	}
	
	/**
	 * Removes template variables
	 * 
	 * @param array|string $vars variable name or list of variable names to clear
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
	 * Gets the resulting output of a compiled file without printing anything on screen
	 * 
	 * @param string $href file's href
	 * @return string output string
	 * @throws Exception
	 */
	public function parse($href) {
		// File init
		$file = new WTemplateFile($href, $this->baseDir, $this->compileDir);
		
		// Compilation (if needed)
		$code = $file->compile($this->compiler);
		
		// Buffer
		ob_start();
		
		try { // Critical section
			// Adds the php close balise at the begining because it is a whole php file being evaluated
			$eval_result = eval('?>'.$code);
		} catch (Exception $e) {
			// Just stores the exception into $e to throw it later
		}
		
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
	 * Displays a file on the screen
	 * 
	 * @param string $href file's href
	 */
	public function display($href) {
		// Display parsing result
		echo $this->parse($href);
	}
}

?>
