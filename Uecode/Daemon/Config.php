<?php
/**
 * @author Aaron Scherer
 * @date 10/12/12
 */
namespace Uecode\Daemon;



abstract class Config
{
	/**
	 * System is unusable (will throw a Exception as well)
	 */
	const LOG_EMERG = 0;

	/**
	 * Immediate action required (will throw a Exception as well)
	 */
	const LOG_ALERT = 1;

	/**
	 * Critical conditions (will throw a Exception as well)
	 */
	const LOG_CRIT = 2;

	/**
	 * Error conditions
	 */
	const LOG_ERR = 3;

	/**
	 * Warning conditions
	 */
	const LOG_WARNING = 4;

	/**
	 * Normal but significant
	 */
	const LOG_NOTICE = 5;

	/**
	 * Informational
	 */
	const LOG_INFO = 6;

	/**
	 * Debug-level messages
	 */
	const LOG_DEBUG = 7;

	/**
	 * Available log levels
	 *
	 * @var array
	 */
	static public $_logLevels = array(
		self::LOG_EMERG   => 'emerg',
		self::LOG_ALERT   => 'alert',
		self::LOG_CRIT    => 'crit',
		self::LOG_ERR     => 'err',
		self::LOG_WARNING => 'warning',
		self::LOG_NOTICE  => 'notice',
		self::LOG_INFO    => 'info',
		self::LOG_DEBUG   => 'debug',
	);

	/**
	 * Available PHP error levels and their meaning in POSIX loglevel terms
	 * Some ERROR constants are not supported in all PHP versions
	 * and will conditionally be translated from strings to constants,
	 * or else: removed from this mapping at start().
	 *
	 * @var array
	 */
	static public $_logPhpMapping = array(
		E_ERROR               => array( self::LOG_ERR, 'Error' ),
		E_WARNING             => array( self::LOG_WARNING, 'Warning' ),
		E_PARSE               => array( self::LOG_EMERG, 'Parse' ),
		E_NOTICE              => array( self::LOG_DEBUG, 'Notice' ),
		E_CORE_ERROR          => array( self::LOG_EMERG, 'Core Error' ),
		E_CORE_WARNING        => array( self::LOG_WARNING, 'Core Warning' ),
		E_COMPILE_ERROR       => array( self::LOG_EMERG, 'Compile Error' ),
		E_COMPILE_WARNING     => array( self::LOG_WARNING, 'Compile Warning' ),
		E_USER_ERROR          => array( self::LOG_ERR, 'User Error' ),
		E_USER_WARNING        => array( self::LOG_WARNING, 'User Warning' ),
		E_USER_NOTICE         => array( self::LOG_DEBUG, 'User Notice' ),
		'E_RECOVERABLE_ERROR' => array( self::LOG_WARNING, 'Recoverable Error' ),
		'E_DEPRECATED'        => array( self::LOG_NOTICE, 'Deprecated' ),
		'E_USER_DEPRECATED'   => array( self::LOG_NOTICE, 'User Deprecated' ),
	);


	/**
	 * Definitions for all Options
	 *
	 * @var array
	 * @see setOption()
	 * @see getOption()
	 */
	static public $_optionDefinitions = array(
		'authorName'             => array(
			'type'    => 'string/0-50',
			'punch'   => 'Author name',
			'example' => 'Kevin van zonneveld',
			'detail'  => 'Required for forging init.d script',
		),
		'authorEmail'            => array(
			'type'    => 'string/email',
			'punch'   => 'Author e-mail',
			'example' => 'kevin@vanzonneveld.net',
			'detail'  => 'Required for forging init.d script',
		),
		'appName'                => array(
			'type'     => 'string/unix',
			'punch'    => 'The application name',
			'example'  => 'logparser',
			'detail'   => 'Must be UNIX-proof; Required for running daemon',
			'required' => true,
		),
		'appDescription'         => array(
			'type'    => 'string',
			'punch'   => 'Daemon description',
			'example' => 'Parses logfiles of vsftpd and stores them in MySQL',
			'detail'  => 'Required for forging init.d script',
		),
		'appDir'                 => array(
			'type'     => 'string/existing_dirpath',
			'default'  => '@dirname({SERVER.SCRIPT_NAME})',
			'punch'    => 'The home directory of the daemon',
			'example'  => '/usr/local/logparser',
			'detail'   => 'Highly recommended to set this yourself',
			'required' => true,
		),
		'appExecutable'          => array(
			'type'     => 'string/existing_filepath',
			'default'  => '@basename({SERVER.SCRIPT_NAME})',
			'punch'    => 'The executable daemon file',
			'example'  => 'logparser.php',
			'detail'   => 'Recommended to set this yourself; Required for init.d',
			'required' => true
		),
		'logVerbosity'           => array(
			'type'     => 'number/0-7',
			'default'  => self::LOG_INFO,
			'punch'    => 'Messages below this log level are ignored',
			'example'  => '',
			'detail'   => 'Not written to logfile; not displayed on screen',
			'required' => true,
		),
		'logLocation'            => array(
			'type'     => 'string/creatable_filepath',
			'default'  => '/var/log/{OPTIONS.appName}.log',
			'punch'    => 'The log filepath',
			'example'  => '/var/log/logparser_daemon.log',
			'detail'   => 'Not applicable if you use PEAR Log',
			'required' => false,
		),
		'logPhpErrors'           => array(
			'type'     => 'boolean',
			'default'  => true,
			'punch'    => 'Reroute PHP errors to log function',
			'detail'   => '',
			'required' => true,
		),
		'logFilePosition'        => array(
			'type'     => 'boolean',
			'default'  => false,
			'punch'    => 'Show file in which the log message was generated',
			'detail'   => '',
			'required' => true,
		),
		'logTrimAppDir'          => array(
			'type'     => 'boolean',
			'default'  => true,
			'punch'    => 'Strip the application dir from file positions in log msgs',
			'detail'   => '',
			'required' => true,
		),
		'logLinePosition'        => array(
			'type'     => 'boolean',
			'default'  => true,
			'punch'    => 'Show the line number in which the log message was generated',
			'detail'   => '',
			'required' => true,
		),
		'appRunAsUID'            => array(
			'type'     => 'number/0-65000',
			'default'  => 0,
			'punch'    => 'The user id under which to run the process',
			'example'  => '1000',
			'detail'   => 'Defaults to root which is insecure!',
			'required' => true,
		),
		'appUser'                => array(
			'type'     => 'string',
			'default'  => 'root',
			'punch'    => 'The user name under which to run the process',
			'example'  => 'www-data',
			'detail'   => 'Defaults to root which is insecure!',
			'required' => false,
		),
		'appRunAsGID'            => array(
			'type'     => 'number/0-65000',
			'default'  => 0,
			'punch'    => 'The group id under which to run the process',
			'example'  => '1000',
			'detail'   => 'Defaults to root which is insecure!',
			'required' => true,
		),
		'appGroup'               => array(
			'type'     => 'string',
			'default'  => 'root',
			'punch'    => 'The group name under which to run the process',
			'example'  => 'www-data',
			'detail'   => 'Defaults to root which is insecure!',
			'required' => false,
		),
		'appPidLocation'         => array(
			'type'     => 'string/unix_filepath',
			'default'  => '/var/run/{OPTIONS.appName}/{OPTIONS.appName}.pid',
			'punch'    => 'The pid filepath',
			'example'  => '/var/run/logparser/logparser.pid',
			'detail'   => '',
			'required' => true,
		),
		'appChkConfig'           => array(
			'type'    => 'string',
			'default' => '- 99 0',
			'punch'   => 'chkconfig parameters for init.d',
			'detail'  => 'runlevel startpriority stoppriority',
		),
		'appDieOnIdentityCrisis' => array(
			'type'     => 'boolean',
			'default'  => true,
			'punch'    => 'Kill daemon if it cannot assume the identity',
			'detail'   => '',
			'required' => true,
		),
		'sysMaxExecutionTime'    => array(
			'type'    => 'number',
			'default' => 0,
			'punch'   => 'Maximum execution time of each script in seconds',
			'detail'  => '0 is infinite',
		),
		'sysMaxInputTime'        => array(
			'type'    => 'number',
			'default' => 0,
			'punch'   => 'Maximum time to spend parsing request data',
			'detail'  => '0 is infinite',
		),
		'sysMemoryLimit'         => array(
			'type'    => 'string',
			'default' => '128M',
			'punch'   => 'Maximum amount of memory a script may consume',
			'detail'  => '0 is infinite',
		),
		'runTemplateLocation'    => array(
			'type'    => 'string/existing_filepath',
			'default' => false,
			'punch'   => 'The filepath to a custom autorun Template',
			'example' => '/etc/init.d/skeleton',
			'detail'  => 'Sometimes it\'s better to stick with the OS default,
                and use something like /etc/default/<name> for customization',
		),
	);


	/**
	 * Available signal handlers
	 * setSigHandler can overwrite these values individually.
	 *
	 * Available POSIX SIGNALS and their PHP handler functions.
	 * Some SIGNALS constants are not supported in all PHP versions
	 * and will conditionally be translated from strings to constants,
	 * or else: removed from this mapping at start().
	 *
	 * 'kill -l' gives you a list of signals available on your UNIX.
	 * Eg. Ubuntu:
	 *
	 *  1) SIGHUP      2) SIGINT      3) SIGQUIT      4) SIGILL
	 *  5) SIGTRAP      6) SIGABRT      7) SIGBUS      8) SIGFPE
	 *  9) SIGKILL    10) SIGUSR1    11) SIGSEGV    12) SIGUSR2
	 * 13) SIGPIPE    14) SIGALRM    15) SIGTERM    17) SIGCHLD
	 * 18) SIGCONT    19) SIGSTOP    20) SIGTSTP    21) SIGTTIN
	 * 22) SIGTTOU    23) SIGURG      24) SIGXCPU    25) SIGXFSZ
	 * 26) SIGVTALRM  27) SIGPROF    28) SIGWINCH    29) SIGIO
	 * 30) SIGPWR      31) SIGSYS      33) SIGRTMIN    34) SIGRTMIN+1
	 * 35) SIGRTMIN+2  36) SIGRTMIN+3  37) SIGRTMIN+4  38) SIGRTMIN+5
	 * 39) SIGRTMIN+6  40) SIGRTMIN+7  41) SIGRTMIN+8  42) SIGRTMIN+9
	 * 43) SIGRTMIN+10 44) SIGRTMIN+11 45) SIGRTMIN+12 46) SIGRTMIN+13
	 * 47) SIGRTMIN+14 48) SIGRTMIN+15 49) SIGRTMAX-15 50) SIGRTMAX-14
	 * 51) SIGRTMAX-13 52) SIGRTMAX-12 53) SIGRTMAX-11 54) SIGRTMAX-10
	 * 55) SIGRTMAX-9  56) SIGRTMAX-8  57) SIGRTMAX-7  58) SIGRTMAX-6
	 * 59) SIGRTMAX-5  60) SIGRTMAX-4  61) SIGRTMAX-3  62) SIGRTMAX-2
	 * 63) SIGRTMAX-1  64) SIGRTMAX
	 *
	 * SIG_IGN, SIG_DFL, SIG_ERR are no real signals
	 *
	 * @var array
	 * @see setSigHandler()
	 */
	static public $_sigHandlers = array(
		SIGHUP        => array( 'self', 'defaultSigHandler' ),
		SIGINT        => array( 'self', 'defaultSigHandler' ),
		SIGQUIT       => array( 'self', 'defaultSigHandler' ),
		SIGILL        => array( 'self', 'defaultSigHandler' ),
		SIGTRAP       => array( 'self', 'defaultSigHandler' ),
		SIGABRT       => array( 'self', 'defaultSigHandler' ),
		'SIGIOT'      => array( 'self', 'defaultSigHandler' ),
		SIGBUS        => array( 'self', 'defaultSigHandler' ),
		SIGFPE        => array( 'self', 'defaultSigHandler' ),
		SIGUSR1       => array( 'self', 'defaultSigHandler' ),
		SIGSEGV       => array( 'self', 'defaultSigHandler' ),
		SIGUSR2       => array( 'self', 'defaultSigHandler' ),
		SIGPIPE       => SIG_IGN,
		SIGALRM       => array( 'self', 'defaultSigHandler' ),
		SIGTERM       => array( 'self', 'defaultSigHandler' ),
		'SIGSTKFLT'   => array( 'self', 'defaultSigHandler' ),
		'SIGCLD'      => array( 'self', 'defaultSigHandler' ),
		'SIGCHLD'     => array( 'self', 'defaultSigHandler' ),
		SIGCONT       => array( 'self', 'defaultSigHandler' ),
		SIGTSTP       => array( 'self', 'defaultSigHandler' ),
		SIGTTIN       => array( 'self', 'defaultSigHandler' ),
		SIGTTOU       => array( 'self', 'defaultSigHandler' ),
		SIGURG        => array( 'self', 'defaultSigHandler' ),
		SIGXCPU       => array( 'self', 'defaultSigHandler' ),
		SIGXFSZ       => array( 'self', 'defaultSigHandler' ),
		SIGVTALRM     => array( 'self', 'defaultSigHandler' ),
		SIGPROF       => array( 'self', 'defaultSigHandler' ),
		SIGWINCH      => array( 'self', 'defaultSigHandler' ),
		'SIGPOLL'     => array( 'self', 'defaultSigHandler' ),
		SIGIO         => array( 'self', 'defaultSigHandler' ),
		'SIGPWR'      => array( 'self', 'defaultSigHandler' ),
		'SIGSYS'      => array( 'self', 'defaultSigHandler' ),
		SIGBABY       => array( 'self', 'defaultSigHandler' ),
		'SIG_BLOCK'   => array( 'self', 'defaultSigHandler' ),
		'SIG_UNBLOCK' => array( 'self', 'defaultSigHandler' ),
		'SIG_SETMASK' => array( 'self', 'defaultSigHandler' ),
	);
}
