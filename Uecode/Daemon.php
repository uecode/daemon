<?php

namespace Uecode;

/**
 * Daemon. Create daemons
 *
 * Requires PHP build with --enable-cli --with-pcntl.
 * Only runs on *NIX systems, because Windows lacks of the pcntl ext.
 *
 * @author    Aaron Scherer <aequasi@gmail.com>
 * @license   MIT
 * @link      https://github.com/uecode/daemon
 *
 */


use Uecode\Daemon\Config;
use Uecode\Daemon\Exception;
use Uecode\Daemon\Options;
use Uecode\Daemon\OS;

class Daemon
{
	/**
	 * The current process identifier
	 *
	 * @var integer
	 */
	protected $_processId = 0;

	/**
	 * Has daemon run _die
	 *
	 * @var boolean
	 */
	protected $_isDying = false;

	/**
	 * Has daemon ran _fork
	 *
	 * @var boolean
	 */
	protected $_isFork = false;

	/**
	 * Option Object
	 *
	 * @var mixed object or boolean
	 */
	protected $_option = false;

	/**
	 * OS Object
	 *
	 * @var mixed object or boolean
	 */
	protected $_os = false;
	protected $_daemonConfig = false;

	/**
	 * Making the class non-abstract with a protected constructor does a better
	 * job of preventing instantiation than just marking the class as abstract.
	 *
	 * @see start()
	 */
	public function __construct( array $configs )
	{

		// Quickly initialize some defaults like usePEAR
		// by adding the $premature flag
		$this->_optionsInit( true );
		$this->setOptions( $configs );

		if ( $this->opt( 'logPhpErrors' ) ) {
			set_error_handler( array( 'self', 'phpErrors' ), E_ALL );
		}

		// Check the PHP configuration
		if ( !defined( 'SIGHUP' ) ) {
			trigger_error( 'PHP is compiled without --enable-pcntl directive', E_USER_ERROR );
		}

		// Check for CLI
		if ( ( php_sapi_name() !== 'cli' ) ) {
			trigger_error( 'You can only create daemon from the command line (CLI-mode)', E_USER_ERROR );
		}

		// Check for POSIX
		if ( !function_exists( 'posix_getpid' ) ) {
			trigger_error( 'PHP is compiled without --enable-posix directive', E_USER_ERROR );
		}

		// Enable Garbage Collector (PHP >= 5.3)
		if ( function_exists( 'gc_enable' ) ) {
			gc_enable();
		}

		// Initialize & check variables
		if ( false === $this->_optionsInit( false ) ) {
			if ( is_object( $this->_option ) && is_array( $this->_option->errors ) ) {
				foreach ( $this->_option->errors as $error ) {
					$this->notice( $error );
				}
			}
			trigger_error( 'Crucial options are not set. Review log:', E_USER_ERROR );
		}
	}

	/**
	 * Spawn daemon process.
	 *
	 * @return boolean
	 * @see iterate()
	 * @see stop()
	 * @see autoload()
	 * @see _optionsInit()
	 * @see _summon()
	 */
	public function start()
	{
		// Conditionally add loglevel mappings that are not supported in
		// all PHP versions.
		// They will be in string representation and have to be
		// converted & unset
		foreach ( Config::$_logPhpMapping as $phpConstant => $props ) {
			if ( !is_numeric( $phpConstant ) ) {
				if ( defined( $phpConstant ) ) {
					Config::$_logPhpMapping[ constant( $phpConstant ) ] = $props;
				}
				unset( Config::$_logPhpMapping[ $phpConstant ] );
			}
		}
		// Same goes for POSIX signals. Not all Constants are available on
		// all platforms.
		foreach ( Config::$_sigHandlers as $signal => $handler ) {
			if ( is_string( $signal ) || !$signal ) {
				if ( defined( $signal ) && ( $const = constant( $signal ) ) ) {
					Config::$_sigHandlers[ $const ] = $handler;
				}
				unset( Config::$_sigHandlers[ $signal ] );
			}
		}

		// Become daemon
		return $this->_summon();
	}

	/**
	 * Protects your daemon by e.g. clearing statcache. Can optionally
	 * be used as a replacement for sleep as well.
	 *
	 * @param integer $sleepSeconds Optionally put your daemon to rest for X s.
	 *
	 * @return void
	 * @see start()
	 * @see stop()
	 */
	public function iterate( $sleepSeconds = 0 )
	{
		$this->_optionObjSetup();
		if ( $sleepSeconds !== 0 ) {
			usleep( $sleepSeconds * 1000000 );
		}

		clearstatcache();

		// Garbage Collection (PHP >= 5.3)
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		return true;
	}

	/**
	 * Stop daemon process.
	 *
	 * @return void
	 * @see start()
	 */
	public function stop()
	{
		$pid = $this->fileread( $this->opt( 'appPidLocation' ) );
		$this->info( 'Stopping {appName} - Process: ' . $pid );
		$this->_die( false );
	}

	/**
	 * Restart daemon process.
	 *
	 * @return void
	 * @see _die()
	 */
	public function restart()
	{
		$pid = $this->fileread( $this->opt( 'appPidLocation' ) );
		$this->info( 'Restarting {appName} - Process: ' . $pid );
		$this->_die( true );
	}

	/**
	 * Overrule or add signal handlers.
	 *
	 * @param string $signal  Signal constant (e.g. SIGHUP)
	 * @param mixed  $handler Which handler to call on signal
	 *
	 * @return boolean
	 * @see $_sigHandlers
	 */
	public function setSigHandler( $signal, $handler )
	{
		if ( !isset( Config::$_sigHandlers[ $signal ] ) ) {
			// The signal should be defined already
			$this->notice(
				'Can only overrule on of these signal handlers: %s',
				join( ', ', array_keys( Config::$_sigHandlers ) )
			);

			return false;
		}

		// Overwrite on existance
		Config::$_sigHandlers[ $signal ] = $handler;

		return true;
	}

	/**
	 * Sets any option found in $_optionDefinitions
	 * Public interface to talk with with protected option methods
	 *
	 * @param string $name  Name of the Option
	 * @param mixed  $value Value of the Option
	 *
	 * @return boolean
	 */
	public function setOption( $name, $value )
	{
		if ( !$this->_optionObjSetup() ) {
			return false;
		}

		return $this->_option->setOption( $name, $value );
	}

	/**
	 * Sets an array of options found in $_optionDefinitions
	 * Public interface to talk with with protected option methods
	 *
	 * @param array $use_options Array with Options
	 *
	 * @return boolean
	 */
	public function setOptions( $use_options )
	{
		if ( !$this->_optionObjSetup() ) {
			return false;
		}

		return $this->_option->setOptions( $use_options );
	}

	/**
	 * Shortcut for getOption & setOption
	 *
	 * @param string $name Option to set or get
	 *
	 * @return mixed
	 */
	public function opt( $name )
	{
		$args = func_get_args();
		if ( count( $args ) > 1 ) {
			return $this->setOption( $name, $args[ 1 ] );
		} else {
			return $this->getOption( $name );
		}
	}


	/**
	 * Gets any option found in $_optionDefinitions
	 * Public interface to talk with with protected option methods
	 *
	 * @param string $name Name of the Option
	 *
	 * @return mixed
	 */
	public function getOption( $name )
	{
		if ( !$this->_optionObjSetup() ) {
			return false;
		}

		return $this->_option->getOption( $name );
	}

	/**
	 * Gets an array of options found
	 *
	 * @return array
	 */
	public function getOptions()
	{
		if ( !$this->_optionObjSetup() ) {
			return false;
		}

		return $this->_option->getOptions();
	}

	/**
	 * Catches PHP Errors and forwards them to log function
	 *
	 * @param integer $errno   Level
	 * @param string  $errstr  Error
	 * @param string  $errfile File
	 * @param integer $errline Line
	 *
	 * @return boolean
	 */
	public function phpErrors( $errno, $errstr, $errfile, $errline )
	{
		// Ignore suppressed errors (prefixed by '@')
		if ( error_reporting() == 0 ) {
			return;
		}

		// Map PHP error level to Daemon log level
		if ( !isset( Config::$_logPhpMapping[ $errno ][ 0 ] ) ) {
			$this->warning( 'Unknown PHP errorno: %s', $errno );
			$phpLvl = Config::LOG_ERR;
		} else {
			list( $logLvl, $phpLvl ) = Config::$_logPhpMapping[ $errno ];
		}

		// Log it
		// No shortcuts this time!
		$this->log(
			$logLvl,
			'[PHP ' . $phpLvl . '] ' . $errstr,
			$errfile,
			__CLASS__,
			__FUNCTION__,
			$errline
		);

		return true;
	}

	/**
	 * Abbreviate a string. e.g: Kevin van zonneveld -> Kevin van Z...
	 *
	 * @param string  $str    Data
	 * @param integer $cutAt  Where to cut
	 * @param string  $suffix Suffix with something?
	 *
	 * @return string
	 */
	public function abbr( $str, $cutAt = 30, $suffix = '...' )
	{
		if ( strlen( $str ) <= 30 ) {
			return $str;
		}

		$canBe = $cutAt - strlen( $suffix );

		return substr( $str, 0, $canBe ) . $suffix;
	}

	/**
	 * Tries to return the most significant information as a string
	 * based on any given argument.
	 *
	 * @param mixed $arguments Any type of variable
	 *
	 * @return string
	 */
	public function semantify( $arguments )
	{
		if ( is_object( $arguments ) ) {
			return get_class( $arguments );
		}
		if ( !is_array( $arguments ) ) {
			if ( !is_numeric( $arguments ) && !is_bool( $arguments ) ) {
				$arguments = '\'' . $arguments . '\'';
			}

			return $arguments;
		}
		$arr = array();
		foreach ( $arguments as $key=> $val ) {
			if ( is_array( $val ) ) {
				$val = json_encode( $val );
			} elseif ( !is_numeric( $val ) && !is_bool( $val ) ) {
				$val = '\'' . $val . '\'';
			}

			$val = $this->abbr( $val );

			$arr[ ] = $key . ': ' . $val;
		}

		return join( ', ', $arr );
	}

	/**
	 * Logging shortcut
	 *
	 * @return boolean
	 */
	public function emerg()
	{
		$arguments = func_get_args();
		array_unshift( $arguments, __FUNCTION__ );
		call_user_func_array( array( 'self', '_ilog' ), $arguments );

		return false;
	}

	/**
	 * Logging shortcut
	 *
	 * @return boolean
	 */
	public function crit()
	{
		$arguments = func_get_args();
		array_unshift( $arguments, __FUNCTION__ );
		call_user_func_array( array( 'self', '_ilog' ), $arguments );

		return false;
	}

	/**
	 * Logging shortcut
	 *
	 * @return boolean
	 */
	public function err()
	{
		$arguments = func_get_args();
		array_unshift( $arguments, __FUNCTION__ );
		call_user_func_array( array( 'self', '_ilog' ), $arguments );

		return false;
	}

	/**
	 * Logging shortcut
	 *
	 * @return boolean
	 */
	public function warning()
	{
		$arguments = func_get_args();
		array_unshift( $arguments, __FUNCTION__ );
		call_user_func_array( array( 'self', '_ilog' ), $arguments );

		return false;
	}

	/**
	 * Logging shortcut
	 *
	 * @return boolean
	 */
	public function notice()
	{
		$arguments = func_get_args();
		array_unshift( $arguments, __FUNCTION__ );
		call_user_func_array( array( 'self', '_ilog' ), $arguments );

		return true;
	}

	/**
	 * Logging shortcut
	 *
	 * @return boolean
	 */
	public function info()
	{
		$arguments = func_get_args();
		array_unshift( $arguments, __FUNCTION__ );
		call_user_func_array( array( 'self', '_ilog' ), $arguments );

		return true;
	}

	/**
	 * Logging shortcut
	 *
	 * @return boolean
	 */
	public function debug()
	{
		$arguments = func_get_args();
		array_unshift( $arguments, __FUNCTION__ );
		call_user_func_array( array( 'self', '_ilog' ), $arguments );

		return true;
	}

	/**
	 * Internal logging function. Bridge between shortcuts like:
	 * err(), warning(), info() and the actual log() function
	 *
	 * @param mixed $level As string or constant
	 * @param mixed $str   Message
	 *
	 * @return boolean
	 */
	protected function _ilog( $level, $str )
	{
		$arguments = func_get_args();
		$level     = $arguments[ 0 ];
		$format    = $arguments[ 1 ];


		if ( is_string( $level ) ) {
			if ( false === ( $l = array_search( $level, Config::$_logLevels ) ) ) {
				$this->log( Config::LOG_EMERG, 'No such loglevel: ' . $level );
			} else {
				$level = $l;
			}
		}

		unset( $arguments[ 0 ] );
		unset( $arguments[ 1 ] );

		$str = $format;
		if ( count( $arguments ) ) {
			foreach ( $arguments as $k => $v ) {
				$arguments[ $k ] = $this->semantify( $v );
			}
			$str = vsprintf( $str, $arguments );
		}

		$this->_optionObjSetup();
		$str = preg_replace_callback(
			'/\{([^\{\}]+)\}/is',
			array( $this->_option, 'replaceVars' ),
			$str
		);


		$history  = 2;
		$dbg_bt   = @debug_backtrace();
		$class    = (string)@$dbg_bt[ ( $history - 1 ) ][ 'class' ];
		$function = (string)@$dbg_bt[ ( $history - 1 ) ][ 'function' ];
		$file     = (string)@$dbg_bt[ $history ][ 'file' ];
		$line     = (string)@$dbg_bt[ $history ][ 'line' ];
		return $this->log( $level, $str, $file, $class, $function, $line );
	}

	/**
	 * Almost every deamon requires a log file, this function can
	 * facilitate that. Also handles class-generated errors, chooses
	 * either PEAR handling or PEAR-independant handling, depending on:
	 * $this->opt('usePEAR').
	 * Also supports PEAR_Log if you referenc to a valid instance of it
	 * in $this->opt('usePEARLogInstance').
	 *
	 * It logs a string according to error levels specified in array:
	 * Config::$_logLevels (0 is fatal and handles daemon's death)
	 *
	 * @param integer $level    What function the log record is from
	 * @param string  $str      The log record
	 * @param string  $file     What code file the log record is from
	 * @param string  $class    What class the log record is from
	 * @param string  $function What function the log record is from
	 * @param integer $line     What code line the log record is from
	 *
	 * @throws Exception
	 * @return boolean
	 * @see _logLevels
	 * @see logLocation
	 */
	public function log(
		$level,
		$str,
		$file = false,
		$class = false,
		$function = false,
		$line = false
	) {
		// If verbosity level is not matched, don't do anything
		if ( null === $this->opt( 'logVerbosity' )
			|| false === $this->opt( 'logVerbosity' )
		) {
			// Somebody is calling log before launching daemon..
			// fair enough, but we have to init some log options
			$this->_optionsInit( true );
		}
		if ( !$this->opt( 'appName' ) ) {
			// Not logging for anything without a name
			return false;
		}

		if ( $level > $this->opt( 'logVerbosity' ) ) {
			return true;
		}

		// Make the tail of log massage.
		$log_tail = '';
		if ( $level < Config::LOG_NOTICE ) {
			if ( $this->opt( 'logFilePosition' ) ) {
				if ( $this->opt( 'logTrimAppDir' ) ) {
					$file = substr( $file, strlen( $this->opt( 'appDir' ) ) );
				}

				$log_tail .= ' [f:' . $file . ']';
			}
			if ( $this->opt( 'logLinePosition' ) ) {
				$log_tail .= ' [l:' . $line . ']';
			}
		}

		// Save resources if arguments are passed.
		// But by falling back to debug_backtrace() it still works
		// if someone forgets to pass them.
		if ( function_exists( 'debug_backtrace' ) && ( !$file || !$line ) ) {
			$dbg_bt   = @debug_backtrace();
			$class    = ( isset( $dbg_bt[ 1 ][ 'class' ] ) ? $dbg_bt[ 1 ][ 'class' ] : '' );
			$function = ( isset( $dbg_bt[ 1 ][ 'function' ] ) ? $dbg_bt[ 1 ][ 'function' ] : '' );
			$file     = $dbg_bt[ 0 ][ 'file' ];
			$line     = $dbg_bt[ 0 ][ 'line' ];
		}

		// Determine what process the log is originating from and forge a logline
		//$str_ident = '@'.substr($this->_whatIAm(), 0, 1).'-'.posix_getpid();
		$str_date  = '[' . date( 'M d H:i:s' ) . ']';
		$str_level = str_pad( Config::$_logLevels[ $level ] . '', 8, ' ', STR_PAD_LEFT );
		$log_line  = $str_date . ' ' . $str_level . ': ' . $str . $log_tail; // $str_ident

		$non_debug     = ( $level < Config::LOG_DEBUG );
		$log_succeeded = true;
		$log_echoed    = false;

		if ( !$this->isInBackground() && $non_debug && !$log_echoed ) {
			// It's okay to echo if you're running as a foreground process.
			// Maybe the command to write an init.d file was issued.
			// In such a case it's important to echo failures to the
			// STDOUT
			echo $log_line . "\n";
			$log_echoed = true;
			// but still try to also log to file for future reference
		}

		if ( !$this->opt( 'logLocation' ) ) {
			throw new Exception( 'Either use PEAR Log or specify ' .
				'a logLocation' );
		}

		// 'Touch' logfile
		if ( !file_exists( $this->opt( 'logLocation' ) ) ) {
			file_put_contents( $this->opt( 'logLocation' ), '' );
		}

		// Not writable even after touch? Allowed to echo again!!
		if ( !is_writable( $this->opt( 'logLocation' ) )
			&& $non_debug && !$log_echoed
		) {
			echo $log_line . "\n";
			$log_echoed    = true;
			$log_succeeded = false;
		}

		// Append to logfile
		$f = file_put_contents(
			$this->opt( 'logLocation' ),
			$log_line . "\n",
			FILE_APPEND
		);
		if ( !$f ) {
			$log_succeeded = false;
		}

		// These are pretty serious errors
		if ( $level < Config::LOG_ERR ) {
			// An emergency logentry is reason for the deamon to
			// die immediately
			if ( $level === Config::LOG_EMERG ) {
				$this->_die();
			}
		}

		return $log_succeeded;
	}

	/**
	 * Uses OS class to write an: 'init.d' script on the filesystem
	 *
	 * @param boolean $overwrite May the existing init.d file be overwritten?
	 *
	 * @return boolean
	 */
	public function writeAutoRun( $overwrite = false )
	{
		// Init Options (needed for properties of init.d script)
		if ( false === $this->_optionsInit( false ) ) {
			return false;
		}

		// Init OS Object
		if ( !$this->_osObjSetup() ) {
			return false;
		}

		// Get daemon properties
		$options = $this->getOptions();

		// Try to write init.d
		$res = Config::$_osObj->writeAutoRun( $options, $overwrite );
		if ( false === $res ) {
			if ( is_array( $this->_os->errors ) ) {
				foreach ( $this->_os->errors as $error ) {
					$this->notice( $error );
				}
			}

			return $this->warning( 'Unable to create startup file' );
		}

		if ( $res === true ) {
			$this->notice( 'Startup was already written' );

			return true;
		} else {
			$this->notice( 'Startup written to %s', $res );
		}

		return $res;
	}

	/**
	 * Default signal handler.
	 * You can overrule various signals with the
	 * setSigHandler() method
	 *
	 * @param integer $signo The posix signal received.
	 *
	 * @return void
	 * @see setSigHandler()
	 * @see $_sigHandlers
	 */
	public function defaultSigHandler( $signo )
	{
		// Must be public or else will throw a
		// fatal error: Call to protected method
		$this->debug( 'Received signal: %s', $signo );

		switch ( $signo ) {
			case SIGTERM:
				// Handle shutdown tasks
				if ( $this->isInBackground() ) {
					$this->_die();
				} else {
					exit;
				}
				break;
			case SIGHUP:
				// Handle restart tasks
				$this->debug( 'Received signal: restart' );
				break;
			case SIGCHLD:
				// A child process has died
				$this->debug( 'Received signal: child' );
				while ( pcntl_wait( $status, WNOHANG OR WUNTRACED ) > 0 ) {
					usleep( 1000 );
				}
				break;
			default:
				// Handle all other signals
				break;
		}
	}

	/**
	 * Whether the class is already running in the background
	 *
	 * @return boolean
	 */
	public function isInBackground()
	{
		return $this->isRunning();
	}

	/**
	 * Whether the our daemon is being killed, you might
	 * want to include this in your loop
	 *
	 * @return boolean
	 */
	public function isDying()
	{
		return $this->_isDying;
	}

	/**
	 * Check if a previous process with same pidfile was already running
	 *
	 * @return boolean
	 */
	public function isRunning()
	{
		$appPidLocation = $this->opt( 'appPidLocation' );

		if ( !file_exists( $appPidLocation ) ) {
			echo "Pid File not found\n";
			unset( $appPidLocation );
			return false;
		}

		$pid = $this->fileread( $appPidLocation );
		if ( !$pid ) {
			echo "Pid File empty\n";
			return false;
		}

		// Ping app
		if ( !posix_kill( intval( $pid ), 0 ) ) {
			// Not responding so unlink pidfile
			@unlink( $appPidLocation );

			echo "Orphaned pidfile found and removed: {$appPidLocation}. Previous process crashed?\n";
			return false;
		}

		return true;
	}


	/**
	 * Put the running script in background
	 *
	 * @return bool
	 */
	protected function _summon()
	{
		$logLoc = $this->opt( 'logLocation' );

		$this->notice( 'Starting {appName} daemon, output in: %s', $logLoc );

		// Allowed?
		if ( $this->isRunning() ) {
			return $this->emerg( '{appName} daemon is still running. Exiting' );
		}

		// Reset Process Information
		$this->_processId = 0;
		$this->_isFork    = false;

		// Fork process!
		if ( !$this->_fork() ) {
			return $this->emerg( 'Unable to fork' );
		}

		// Additional PID succeeded check
		if ( !is_numeric( $this->_processId ) || $this->_processId < 1 ) {
			return $this->emerg( 'No valid pid: %s', $this->_processId );
		}

		// Change umask
		@umask( 0 );

		// Write pidfile
		$p = $this->_writePid( $this->opt( 'appPidLocation' ), $this->_processId );
		if ( $p === false ) {
			return $this->emerg( 'Unable to write pid file {appPidLocation}' );
		}

		// Change identity. maybe
		if ( exec( "id -u" ) === 0 ) {
			$c = $this->_changeIdentity( $this->opt( 'appRunAsGID' ), $this->opt( 'appRunAsUID' ) );
			if ( $c === false ) {
				$this->crit( 'Unable to change identity' );
				$this->emerg( 'Cannot continue after this' );
			}
		}

		// Important for daemons
		// See http://www.php.net/manual/en/function.pcntl-signal.php
		declare( ticks = 1 ) ;

		// Setup signal handlers
		// Handlers for individual signals can be overrulled with
		// setSigHandler()
		foreach ( Config::$_sigHandlers as $signal => $handler ) {
			if ( !is_callable( $handler ) && $handler != SIG_IGN && $handler != SIG_DFL ) {
				return $this->emerg(
					'You want to assign signal %s to handler %s but it\'s not callable',
					$signal,
					$handler
				);
			} else {
				if ( !pcntl_signal( $signal, $handler ) ) {
					return $this->emerg( 'Unable to reroute signal handler: %s', $signal );
				}
			}
		}

		// Change dir
		@chdir( $this->opt( 'appDir' ) );

		return true;
	}

	/**
	 * Determine whether pidfilelocation is valid
	 *
	 * @param string  $pidFilePath Pid location
	 * @param boolean $log         Allow this function to log directly on error
	 *
	 * @return boolean
	 */
	protected function _isValidPidLocation( $pidFilePath, $log = true )
	{
		if ( empty( $pidFilePath ) ) {
			return $this->err(
				'{appName} daemon encountered an empty appPidLocation'
			);
		}

		$pidDirPath = dirname( $pidFilePath );
		$parts      = explode( '/', $pidDirPath );
		if ( count( $parts ) <= 3 || end( $parts ) != $this->opt( 'appName' ) ) {
			// like: /var/run/x.pid
			return $this->err(
				'Since version 0.6.3, the pidfile needs to be ' .
					'in it\'s own subdirectory like: %s/{appName}/{appName}.pid'
			);
		}

		return true;
	}

	/**
	 * Creates pid dir and writes process id to pid file
	 *
	 * @param string  $pidFilePath PID File path
	 * @param integer $pid         PID
	 *
	 * @return boolean
	 */
	protected function _writePid( $pidFilePath = null, $pid = null )
	{
		if ( empty( $pid ) ) {
			return $this->err( '{appName} daemon encountered an empty PID' );
		}

		if ( !$this->_isValidPidLocation( $pidFilePath, true ) ) {
			return false;
		}

		$pidDirPath = dirname( $pidFilePath );

		if ( !$this->_mkdirr( $pidDirPath, 0755 ) ) {
			return $this->err( 'Unable to create directory: %s', $pidDirPath );
		}

		if ( !file_put_contents( $pidFilePath, $pid ) ) {
			return $this->err( 'Unable to write pidfile: %s', $pidFilePath );
		}

		if ( !chmod( $pidFilePath, 0644 ) ) {
			return $this->err( 'Unable to chmod pidfile: %s', $pidFilePath );
			;
		}

		return true;
	}

	/**
	 * Read a file. file_get_contents() leaks memory! (#18031 for more info)
	 *
	 * @param string $filepath
	 *
	 * @return string
	 */
	public function fileread( $filepath )
	{
		return file_get_contents( $filepath );
	}

	/**
	 * Recursive alternative to mkdir
	 *
	 * @param string  $dirPath Directory to create
	 * @param integer $mode    Umask
	 *
	 * @return boolean
	 */
	protected function _mkdirr( $dirPath, $mode )
	{
		is_dir( dirname( $dirPath ) ) || $this->_mkdirr( dirname( $dirPath ), $mode );

		return is_dir( $dirPath ) || @mkdir( $dirPath, $mode );
	}

	/**
	 * Change identity of process & resources if needed.
	 *
	 * @param integer $gid Group identifier (number)
	 * @param integer $uid User identifier (number)
	 *
	 * @return boolean
	 */
	protected function _changeIdentity( $gid = 0, $uid = 0 )
	{
		// What files need to be chowned?
		$chownFiles = array();
		if ( $this->_isValidPidLocation( $this->opt( 'appPidLocation' ), true ) ) {
			$chownFiles[ ] = dirname( $this->opt( 'appPidLocation' ) );
		}
		$chownFiles[ ] = $this->opt( 'appPidLocation' );
		if ( !is_object( $this->opt( 'usePEARLogInstance' ) ) ) {
			$chownFiles[ ] = $this->opt( 'logLocation' );
		}

		// Chown pid- & log file
		// We have to change owner in case of identity change.
		// This way we can modify the files even after we're not root anymore
		foreach ( $chownFiles as $filePath ) {
			// Change File GID
			$doGid = ( filegroup( $filePath ) != $gid ? $gid : false );
			if ( false !== $doGid && !@chgrp( $filePath, intval( $gid ) ) ) {
				return $this->err(
					'Unable to change group of file %s to %s',
					$filePath,
					$gid
				);
			}

			// Change File UID
			$doUid = ( fileowner( $filePath ) != $uid ? $uid : false );
			if ( false !== $doUid && !@chown( $filePath, intval( $uid ) ) ) {
				return $this->err(
					'Unable to change user of file %s to %s',
					$filePath,
					$uid
				);
			}

			// Export correct homedir
			if ( ( $info = posix_getpwuid( $uid ) ) && is_dir( $info[ 'dir' ] ) ) {
				system( 'export HOME="' . $info[ 'dir' ] . '"' );
			}
		}

		// Change Process GID
		$doGid = ( posix_getgid() !== $gid ? $gid : false );
		if ( false !== $doGid && !@posix_setgid( $gid ) ) {
			return $this->err( 'Unable to change group of process to %s', $gid );
		}

		// Change Process UID
		$doUid = ( posix_getuid() !== $uid ? $uid : false );
		if ( false !== $doUid && !@posix_setuid( $uid ) ) {
			return $this->err( 'Unable to change user of process to %s', $uid );
		}

		$group = posix_getgrgid( $gid );
		$user  = posix_getpwuid( $uid );

		return $this->info(
			'Changed identify to %s:%s',
			$group[ 'name' ],
			$user[ 'name' ]
		);
	}

	/**
	 * Fork process and kill parent process, the heart of the 'daemonization'
	 *
	 * @return boolean
	 */
	protected function _fork()
	{
		$this->debug( 'forking {appName} daemon' );
		$pid = pcntl_fork();
		if ( $pid === -1 ) {
			// Error
			return $this->warning( 'Process could not be forked' );
		} else {
			if ( $pid === 0 ) {
				// Child
				$this->_isFork    = true;
				$this->_isDying   = false;
				$this->_processId = posix_getpid();
				return true;
			} else {
				// Parent
				$this->debug( 'Ending {appName} parent process' );
				// Die without attracting attention
				die();
			}
		}
	}

	/**
	 * Return what the current process is: child or parent
	 *
	 * @return string
	 */
	protected function _whatIAm()
	{
		return ( $this->isInBackground() ? 'child' : 'parent' );
	}

	/**
	 * Sytem_Daemon::_die()
	 * Kill the daemon
	 * Keep this function as independent from complex logic as possible
	 *
	 * @param boolean $restart Whether to restart after die
	 *
	 * @return void
	 */
	protected function _die( $restart = false )
	{
		if ( $this->isDying() ) {
			$this->info( 'process already halting' );

			return null;
		}

		$this->_isDying = true;
		// Following caused a bug if pid couldn't be written because of
		// privileges
		// || !file_exists($this->opt('appPidLocation'))
		if ( !$this->isInBackground() ) {
			$this->info( 'halting current process' );
			die();
		}

		$pid = $this->fileread( $this->opt( 'appPidLocation' ) );
		@unlink( $this->opt( 'appPidLocation' ) );

		if ( $restart ) {
			// So instead we should:
			die( exec( join( ' ', $GLOBALS[ 'argv' ] ) . ' > /dev/null &' ) );
		} else {
			passthru( 'kill -9 ' . $pid );
			die();
		}
	}


	/**
	 * Sets up OS instance
	 *
	 * @return boolean
	 */
	protected function _osObjSetup()
	{
		// Create Option Object if nescessary
		if ( !$this->_os ) {
			$this->_os = OS::factory();
		}

		// Still false? This was an error!
		if ( !$this->_os ) {
			return $this->emerg( 'Unable to setup OS object' );
		}

		return true;
	}

	/**
	 * Sets up Option Object instance
	 *
	 * @return boolean
	 */
	protected function _optionObjSetup()
	{
		// Create Option Object if nescessary
		if ( !$this->_option ) {
			$this->_option = new Options( Config::$_optionDefinitions );
		}

		// Still false? This was an error!
		if ( !$this->_option ) {
			return $this->emerg( 'Unable to setup Options object. ' );
		}

		return true;
	}

	/**
	 * Checks if all the required options are set.
	 * Initializes, sanitizes & defaults unset variables
	 *
	 * @param boolean $premature Whether to do a premature option init
	 *
	 * @return mixed integer or boolean
	 */
	protected function _optionsInit( $premature = false )
	{
		if ( !$this->_optionObjSetup() ) {
			return false;
		}

		return $this->_option->init( $premature );
	}
}
