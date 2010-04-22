<?php

/**
 * Command-line interface utility class
 *
 * @author Misha Yurasov <me@yurasov.me>
 * @copyright 2010
 * @package CLIUtil
 */

class CLIUtil
{
  // <Constants>

  const VERSION = '1.0 beta';

  // Error codes

  const ERROR_MISC = -1;
  const ERROR_OK = 0;

  // Parameter type constants

  const PARAM_TYPE_AUTO     = 0;
  const PARAM_TYPE_INTEGER  = 1;
  const PARAM_TYPE_STRING   = 2;
  const PARAM_TYPE_BOOLEAN  = 3;
  const PARAM_TYPE_ARRAY    = 4;
  const PARAM_TYPE_TIME_SEC = 5;  // Time interval [seconds]: #s/m/h/d/w

  // Output message types

  const MESSAGE_ERROR       = 'e';  // Error messages
  const MESSAGE_STATUS      = 's';  // Startup and finish messages
  const MESSAGE_INFORMATION = 'i';  // Process information messages

  // Verbocity-specific flags

  const VERB_SILENSE      = '-';  // Be quiet
  const VERB_PROGRESS     = 'p';  // Progress

  // Logging-specific flags

  const LOG_DISABLE     = '-';  // Disable logging
  const LOG_PROGRESS    = 'p';  // Create progress file
  const LOG_OVERWRITE   = 'o';  // Overwrite log file (otherwise - append)

  // <Variables>

  private $declared_parameters = array();       // Expected script parameters
  private $parameters_read = false;             // Are parameters already read?
  private $progress_last_item = 0;              // Last item number of progress update
  private $progress_last_time = 0.0;            // Last time of progress update
  private $progress_start_time = 0.0;           // Start time of progress
  private $verbocity_options = array();         // Parsed verbopcity options
  private $logging_options = array();           // Parsed logging options
  private $progress_tags = array();             // Progress format tags
  private $progress_tag_names = array();        // Tags names cache
  private $progress_last_console_output = null; // Progress console output cache
  private $progress_last_file_output = '';      // Progress file output cache
  private $progress_rotator_index = 0;          // Progress rotator element index
  private $progress_refresh_interval = null;    // Progrtess refresh interval
  private $message_log_fp = null;               // Message log file pointer
  private $message_log_start_time = 0.0;        // Message log start time
  private $time_started = 0.0;                  // Start time
  private $time_total = 0.0;                    // Total time
  private $started = false;                     // start() called
  private $ended = false;                       // end() called
  
  // <editor-fold defaultstate="collapsed" desc="Default options">

  private $default_options = array(

    // Script name
    'script_name' => '',

    // Script version
    'script_version' => '',

    // Script detailed description
    'script_description' => '',

    // Maximum width of text output
    'max_output_width' => 80,

    // Default logging options
    'logging_default' => null,

    // Default verbocity options
    'verbocity_default' => null,

    // Log file path
    'log_file' => null,

    // Progress file path
    'progress_file' => null,

    // Progress bar format. Also possible: %item%, %total%, %time_passed%, %rotator%
    'progress_console_format' => '%percent% done [%bar%] left: %eta% %rotator%',

    // Digits after point in percents
    'progress_percents_precision' => 1,

    // Digits after point in speed
    'progress_speed_precision' => 2,

    // Digits after point in time
    'progress_time_precision' => 0,

    // Minimum refresh time of progress bar in console
    'progress_console_refresh_interval' => 0.5,

    // Minimum refresh time or progress file
    'progress_file_refresh_interval' => 5,

    // Total number of items for progress tracking
    'progress_items_total' => 0,

    // Rotator chars
    'progress_rotator_sequence' => array('|', '/', '-', '\\'),

    // Progress operation name
    'progress_operation_title' => '',

    // Progress file format
    'progress_log_format' => '',

    // Automatic start and end status messages (%time_current% for time, %time_passed% for passed time)
    'status_start_message' => 'Started at %time_current%',
    'status_end_message' => 'Finished at %time_current% (+%time_passed%)',

    // Status messages time format (accepted by date() function)
    'status_time_format' => 'r', // RFC 2822 formatted date

    // Status message time passed precision
    'status_time_precision' => 3
  );

  // </editor-fold>

  // <Properties>

  /**
   * Options storage
   *
   * @property-read $options
   * @var CLIUtil_Storage
   */
  private $options;

  // <Accessors>

  // Script parameters

  private $parameters = array();

  /**
   * Get parameter by name
   *
   * @param string $name
   * @return ?
   */
  public function getParameter($name)
  {
    if (!$this->parameters_read)
      $this->_read_parameters();
    
    if (array_key_exists($name, $this->parameters))
    {
      return $this->parameters[$name];
    }
    else
    {
      throw new Exception("Parameter '$name' is not declared or read", self::ERROR_MISC);
    }
  }

  /**
   * Get parameters as array
   *
   * @return array
   */
  public function getParameters()
  {
    if (!$this->parameters_read)
      $this->_read_parameters();

    return $this->parameters;
  }

  //

  /**
   * Get time passed since start() or __construct()
   *
   * @param boolean $return_as_string
   * @return float|string Time passed
   */
  public function getTimePassed($return_as_string = false)
  {
    // Update time passed
    
    if (!$this->ended)
      $this->time_total = microtime(true) - $this->time_started;

    return $return_as_string
      ? CLIUtil_Utils::formatTime($this->time_total, $this->options['status_time_precision'], true, 1, true)
      : $this->time_total;
  }

  // <Public functions>

  /**
   * Constructor
   *
   * @param $options array Options
   */
  public function __construct($options = array())
  {
    // Save start time (assumed to be overwritten by $this->start() call)
    $this->time_started = microtime(true);

    // Set initial looging and verbocity options
    $this->_init_logging_options();
    $this->_init_verbocity_options();

    // Initialize user options

    $this->_init_default_options();
    $this->options = new CLIUtil_Storage($this->default_options);
    $this->options->set($options);

    // Declare standard parameters

    $this->declareParameter('help', '?', false, self::PARAM_TYPE_BOOLEAN, 'Display help');

    $this->declareParameter('logging', 'l', $this->options['logging_default'],
      self::PARAM_TYPE_STRING, 'Logging options');

    $this->declareParameter('verbocity', 'v', $this->options['verbocity_default'],
      self::PARAM_TYPE_STRING, 'Verbocity options');
  }

  /**
   * Destructor
   */
  public function  __destruct()
  {
    if ($this->started && !$this->ended)
      $this->end();

    // Close log file
    $this->_log_end();
  }

  /**
   * Properties getter
   */
  public function __get($name)
  {
    switch ($name)
    {
      case 'options':
        return $this->$name;
        break;

      default:
        throw new Exception("Property '" . __CLASS__ . "::$name' doesn't exist", self::ERROR_MISC);
        break;
    }
  }

  /**
   * Should be called before *any* work is started
   */
  public function start()
  {
    if (!$this->parameters_read)
      $this->_read_parameters();

    // Save start time
    $this->time_started = microtime(true);

    // Output start message

    if ($this->options['status_start_message'] != '')
      $this->status(str_replace('%time_current%', 
        date($this->options['status_time_format']),
        $this->options['status_start_message']));

    // Sarted
    $this->started = true;
  }

  /**
   * Called after *all* work is done
   * Logging is not shut down at this point
   */
  public function end()
  {
    // Save total work time
    $this->time_total = microtime(true) - $this->time_started;

    // Erase progress
    $this->_erase_progress();

    // Output end message

    if ($this->options['status_end_message'] != '')
      $this->status(str_replace(
        array('%time_current%', '%time_passed%'),
        array(date($this->options['status_time_format']), $this->getTimePassed(true)),
        $this->options['status_end_message']));

    // end() already called
    $this->ended = true;
  }

  /**
   * Declare required parameter
   *
   * @param string $name
   * @param string $alias
   * @param mixed $default_value
   * @param mixed $type
   * @param string $description
   */
  public function declareParameter($name, $alias, $default_value = null, $type = self::PARAM_TYPE_AUTO, $description = '')
  {
    $this->declared_parameters[$name] = array('alias' => $alias,
      'default' => $default_value, 'type' => $type, 'desc' => $description);
    $this->parameters_read = false; // Parameters need to be read
  }

  /*
    Test CLI util v. 0.1
    --------------------

      Lorem ipsum dolor sit amet lorem ipsum dolor sit amet ipsum dolor sit amet
      ipsum dolor sit amet ipsum dolor sit amet ipsum dolor sit amet

    Parameters: name (alias) [type]; default: default value
    -------------------------------------------------------

      help (?) [boolean]; default: true

        Display this help

      verbocity (v) [string]; default: sei

        Verbocity level
  */

  /**
   * Display help message
   */
  public function displayHelp()
  {
    // Name and version

    $text = sprintf("%s v. %s", $this->options['script_name'], $this->options['script_version']);
    $underline = str_repeat('-', strlen($text));
    echo "$underline\n$text\n$underline";

    // Description

    if ($this->options['script_description'] != '')
    {
      $text = CLIUtil_Utils::textAlign($this->options['script_description'], CLIUtil_Utils::TEXT_ALIGN_LEFT,
        $this->options['max_output_width'] - 2, "\n", true, 1, 0);
      $text = CLIUtil_Utils::textIndent($text, '  ', 1, "\n");
      echo "\n\n$text";
    }

    // Parameters

    if (count($this->declared_parameters) > 0)
    {
      $text = "Parameters";
      $underline = str_repeat('-', strlen($text));
      echo "\n\n$text\n$underline";

      foreach ($this->declared_parameters as $declared_parameter_name => $declared_parameter)
      {
        switch ($this->_get_declared_parameter_type($declared_parameter_name))
        {
          case self::PARAM_TYPE_INTEGER:
          {
            $type_name = 'integer';
            $default_value = strval($declared_parameter['default']);
            break;
          }

          case self::PARAM_TYPE_STRING:
          {
            $type_name = 'string';
            $default_value = '"'. $declared_parameter['default'] . '"';
            break;
          }

          case self::PARAM_TYPE_BOOLEAN:
          {
            $type_name = 'boolean';
            $default_value = $declared_parameter['default'] ? 'true' : 'false';
            break;
          }

          case self::PARAM_TYPE_ARRAY:
          {
            $type_name = 'array';
            $default_value = $declared_parameter['default'];
            break;
          }

          case self::PARAM_TYPE_TIME_SEC:
          {
            $type_name = 'time in seconds';
            $default_value = $declared_parameter['default'] .
              ' (' . CLIUtil_Utils::formatTime($this->_time_sec_to_int($declared_parameter['default'])) . ')';
            break;
          }

          default:
          {
            throw new Exception('Wrong parameter type for "' . $declared_parameter_name . '"', self::ERROR_MISC);
            break;
          }
        }

        // Parameter usage
        $text = printf("\n\n  * %s (%s) [%s]; default: %s",
          $declared_parameter_name, $declared_parameter['alias'], $type_name, $default_value);
        $text = CLIUtil_Utils::textAlign($text, CLIUtil_Utils::TEXT_ALIGN_LEFT,
          $this->options['max_output_width'] - 2, "\n", true, 1, 0);

        // Parameter description
        $text = CLIUtil_Utils::textAlign($declared_parameter['desc'], CLIUtil_Utils::TEXT_ALIGN_LEFT,
          $this->options['max_output_width'] - 4, "\n", true, 1, 0);
        $text = CLIUtil_Utils::textIndent($text, '  ', 2);
        echo "\n\n$text";
      }
    }
  }

  /**
   * Reset progress
   *
   * @param array $options
   */
  public function resetProgress($options = array())
  {
    if ($this->logging_options[self::LOG_PROGRESS] 
      || $this->verbocity_options[self::VERB_PROGRESS])
    {
      // Update options:

      $this->options->set($options);

      // Reset progress variables
      
      $this->progress_tags['%total%'] = strval($this->options->get('progress_items_total'));
      $this->progress_tags['%title%'] = $this->options->get('progress_operation_title');
      $this->progress_last_time = 0.0;
      $this->progress_last_console_output = null;
      $this->progress_last_file_output = '';
      $this->progress_rotator_index = 0;

      // Calculate progress refresh interval
      $this->_calculate_progress_refresh_intertval();
    }
  }

  /**
   * Update progress information
   *
   * @param integer $current_item
   */
  public function updateProgress($current_item)
  {
    // $this->progress_refresh_interval === null if no progress options are set
    if (!is_null($this->progress_refresh_interval))
    {
      // Time passed since last call
      $progress_info_time_diff = microtime(true) - $this->progress_last_time;

      // Is progress refresh interval passed?
      $time_for_progress = $progress_info_time_diff >= $this->progress_refresh_interval;

      // Is last item reached?
      $last_item_reached = $current_item >= $this->options->get('progress_items_total');

      if ($time_for_progress  || $last_item_reached)
      {
        // Calculate progress information
        if ($this->progress_last_time == 0) // First call
        {
          // Reset progress
          $this->resetProgress();

          $this->progress_start_time = microtime(true); // Start time
          $this->progress_last_time = $this->progress_start_time; // Last time
          $this->progress_last_item = $current_item; // Last percent

          // Mark progress information as unknown

          $progress_info_eta = -1;
          $progress_info_speed_curr = -1;
          $progress_info_speed_avg = -1;
          $progress_info_time_passed = -1;
          $progress_info_time_diff = -1;

          // Refresh progress (in console and in file)
          $refresh_progress = true;
        }
        else // Sequential call
        {
          $progress_info_time_diff = microtime(true) - $this->progress_last_time; // Current time difference

          $this->progress_last_time += $progress_info_time_diff; // progress_last_time = microtime()
          $items_diff = $current_item - $this->progress_last_item; // Number difference
          $this->progress_last_item = $current_item; // Last item

          $progress_info_time_passed = $this->progress_last_time - $this->progress_start_time; // Total time difference
          $progress_info_speed_avg = $current_item / $progress_info_time_passed; // [% / sec]
          $progress_info_speed_curr = $items_diff / $progress_info_time_diff; // [% / sec]
          $progress_info_eta = ($this->options['progress_items_total'] - $current_item)
            / $progress_info_speed_avg; // Estimated time left [sec]
        }

        // Compose progress text parts:

        // Done part
        $progress_info_done_part = $current_item / $this->options['progress_items_total'];

        // %percent% (41.5%)
        $this->progress_tags['%percent%'] = sprintf('%.'
          . $this->options['progress_percents_precision'] . 'f%%', $progress_info_done_part * 100);

        // %eta% - time left
        $this->progress_tags['%eta%'] = $progress_info_eta == -1 ? '?'
          : CLIUtil_Utils::formatTime($progress_info_eta, $this->options['progress_time_precision'], true, 1, true);

        // %time_passed% - time passed
        $this->progress_tags['%time_passed%'] = $progress_info_time_passed == -1 ? '?'
          : CLIUtil_Utils::formatTime($progress_info_time_passed, $this->options['progress_time_precision'], true, 1, true);

        // %item% - current item
        $this->progress_tags['%item%'] = strval($current_item);

        // %speed_avg% - average speed
        $this->progress_tags['%speed_avg%'] = $progress_info_speed_avg == -1 ? '?'
          : sprintf('%.' . $this->options['progress_speed_precision'] . 'f/s',
            $progress_info_speed_avg);

        // %speed_cur% - current speed
        $this->progress_tags['%speed_cur%'] = $progress_info_speed_curr == -1 ? '?'
          : sprintf('%.' . $this->options['progress_speed_precision'] . 'f/s',
            $progress_info_speed_curr);

        // %rotator%
        $this->progress_tags['%rotator%'] = $this->options['progress_rotator_sequence'][$this->progress_rotator_index];
        $this->progress_rotator_index = ++$this->progress_rotator_index % count($this->options['progress_rotator_sequence']);

        // Draw progress bar

        if ($this->verbocity_options[self::VERB_PROGRESS] &&
          ($progress_info_time_passed >= $this->options['progress_console_refresh_interval']))
            $this->_display_progress($progress_info_done_part);

        // Write progress file

        if ($this->logging_options[self::LOG_PROGRESS] &&
          ($progress_info_time_passed >= $this->options['progress_file_refresh_interval']))
            $this->_log_progress($progress_info_done_part);
      }
    }
  }

  /**
   * Output status message
   *
   * @param string $message
   */
  public function status($message)
  {
    $this->out(self::MESSAGE_STATUS, $message);
  }

  /**
   * Output info message
   *
   * @param string $message
   */
  public function info($message)
  {
    $this->out(self::MESSAGE_INFORMATION, $message);
  }

  /**
   * Output error message
   *
   * @param string $message
   */
  public function error($message)
  {
    $this->out(self::MESSAGE_ERROR, $message);
  }

  /**
   * Output message to console and log
   * Checks if message is allowed by verbocity and logging options
   *
   * @param string $meesage_type
   * @param string $message
   */
  public function out($message_type, $message)
  {
    if ($this->verbocity_options[$message_type])
    {
      // Erase progress
      $this->_erase_progress();

      // Output message to console
      echo $message . "\n";
    }

    if ($this->logging_options[$message_type])
    {
      $this->_log($message);
    }
  }

  // <Private functions>

  /**
   * Is message log enabled?
   * @return boolean
   */
  private function _message_log_enabled()
  {
    return $this->logging_options[self::MESSAGE_STATUS] ||
      $this->logging_options[self::MESSAGE_ERROR] || 
      $this->logging_options[self::MESSAGE_INFORMATION];
  }

  /**
   *
   * @param <type> $message Log message
   */
  private function _log($message)
  {
    // Open log file on first call

    if (is_null($this->message_log_fp))
      $this->_log_start();

    // Log message

    if ($this->message_log_fp)
    {
      fwrite($this->message_log_fp,
        sprintf("[+%.3fs] %s\n", microtime(true) - $this->message_log_start_time, $message));
    }
  }

  /**
   * Start logging
   */
  private function _log_start()
  {
    if ($this->_message_log_enabled())
    {
      $new_log_file = file_exists($this->options['log_file'])
        && (filesize($this->options['log_file']) > 0);

      if (!($this->message_log_fp = @fopen($this->options['log_file'], $this->logging_options[self::LOG_OVERWRITE] ? 'w' : 'a')))
      {
        trigger_error("Failed to open log file '{$this->options['log_file']}' for writing", E_USER_WARNING);
      }
      else
      {
        $this->message_log_start_time = microtime(true);

        // Separator
        if (!$this->logging_options[self::LOG_OVERWRITE] && $new_log_file)
          fwrite($this->message_log_fp, "\n---\n\n");

        // Write header
        fwrite($this->message_log_fp, '[Log started at ' . date('r') . "]\n");
      }
    }
  }

  /**
   * End logging
   */
  private function _log_end()
  {
    if ($this->_message_log_enabled() && $this->message_log_fp)
    {
      // Write footer
      fwrite($this->message_log_fp,
        sprintf("[Log finished at %s (+ %.3fs)]\n",
          date('r'), microtime(true) - $this->message_log_start_time));

      // Close log file
      fclose($this->message_log_fp);

      // Unset log file pointer
      $this->message_log_fp = null;
    }
  }

  /**
   * Read parameters
   *
   * @return array Parameters array || false
   */
  private function _read_parameters()
  {
    // Read arguments
    $args = $this->_read_arguments();

    if (count($this->declared_parameters) > 0)
    {
      foreach ($this->declared_parameters as $param_name => $param_info)
      {
        $arg = null;

        // Read argument by parameter name or alias

        if (array_key_exists($param_name, $args))
        {
          $arg = $args[$param_name];
        }
        else if ($param_info['alias'] != '' && array_key_exists($param_info['alias'], $args))
        {
          $arg = $args[$param_info['alias']];
        }

        // Read parameter
        $this->parameters[$param_name] = $this->_read_parameter_value($arg, $param_name);

        // Create alias for parameter
        $this->parameters[$param_info['alias']] =& $this->parameters[$param_name];
      }

      // Initialization after parameters are read
      $this->_init_from_parameters();

      // Parameters already read
      $this->parameters_read = true;

      return $this->parameters;
    }
  }

  /**
   * Set initial logging options
   */
  private function _init_logging_options()
  {
    // Initial logging options

    $this->logging_options[self::MESSAGE_STATUS] = false;
    $this->logging_options[self::MESSAGE_ERROR] = false;
    $this->logging_options[self::MESSAGE_INFORMATION]= false;
    $this->logging_options[self::LOG_PROGRESS] = false;
    $this->logging_options[self::LOG_OVERWRITE] = false;
  }

  /**
   * Set initial verbocity options
   */
  private function _init_verbocity_options()
  {
    // Initial logging options

    $this->verbocity_options[self::MESSAGE_STATUS] = false;
    $this->verbocity_options[self::MESSAGE_ERROR] = false;
    $this->verbocity_options[self::MESSAGE_INFORMATION]= false;
    $this->verbocity_options[self::VERB_PROGRESS]= false;
  }

  /**
   * Initialization after parameters are read
   */
  private function _init_from_parameters()
  {
    // Read verbocity options

    $this->verbocity_options[self::MESSAGE_STATUS] = $this->_have_verbocity_flag(self::MESSAGE_STATUS);
    $this->verbocity_options[self::MESSAGE_ERROR] = $this->_have_verbocity_flag(self::MESSAGE_ERROR);
    $this->verbocity_options[self::MESSAGE_INFORMATION]= $this->_have_verbocity_flag(self::MESSAGE_INFORMATION);
    $this->verbocity_options[self::VERB_PROGRESS]= $this->_have_verbocity_flag(self::VERB_PROGRESS);

    // Read logging options

    $this->logging_options[self::MESSAGE_STATUS] = $this->_have_logging_flag(self::MESSAGE_STATUS);
    $this->logging_options[self::MESSAGE_ERROR] = $this->_have_logging_flag(self::MESSAGE_ERROR);
    $this->logging_options[self::MESSAGE_INFORMATION]= $this->_have_logging_flag(self::MESSAGE_INFORMATION);
    $this->logging_options[self::LOG_PROGRESS] = $this->_have_logging_flag(self::LOG_PROGRESS);
    $this->logging_options[self::LOG_OVERWRITE] = $this->_have_logging_flag(self::LOG_OVERWRITE);

    // Initialize progress
    if ($this->logging_options[self::LOG_PROGRESS] || $this->verbocity_options[self::VERB_PROGRESS])
      $this->_init_progress();
  }

  /**
   * Initialise progress-related varibles
   */
  private function _init_progress()
  {
    // Init text parts array:

    // Text parts' values
    $this->progress_tags = array(
      '%total%' => '',
      '%percent%' => '',
      '%eta%' => '',
      '%time_passed%' => '',
      '%item%' => '',
      '%speed_avg%' => '',
      '%speed_cur%' => '',
      '%rotator%' => '',
      '%title%' => ''
    );

    // Text parts' keys
    $this->progress_tag_names =
      array_keys($this->progress_tags);

    // Progress title
    $this->options['progress_operation_title'] = $this->options['script_name'];

    // Calculate progress refresh interval
    $this->_calculate_progress_refresh_intertval();
  }

  /**
   * Calculate progress refresh interval
   */
  private function _calculate_progress_refresh_intertval()
  {
    if ($this->logging_options[self::LOG_PROGRESS])
    {
      if ($this->verbocity_options[self::VERB_PROGRESS])
      {
        $this->progress_refresh_interval =
          min($this->options['progress_console_refresh_interval'],
          $this->options['progress_file_refresh_interval']);
      }
      else
      {
        $this->progress_refresh_interval =
          $this->options->get('progress_file_refresh_interval');
      }
    }
    else
    {
      if ($this->verbocity_options[self::VERB_PROGRESS])
      {
        $this->progress_refresh_interval =
          $this->options->get('progress_console_refresh_interval');
      }
      else
      {
        $this->progress_refresh_interval = null; // No progress
      }
    }
  }

  /**
   * Display progress in console
   */
  private function _display_progress($progress_info_done_part)
  {
    // Default: '%percent% [%bar%] Left: %eta%'
    // Also possible: %item%, %total%, %time_passed%, %speed_avg%, %speed_cur%
    $progress_text = $this->options['progress_console_format'];

    // Replace tags
    $progress_text = str_replace($this->progress_tag_names, $this->progress_tags, $progress_text);

    if (strstr($progress_text, '%bar%') !== false)
    {
      // Insert progress bar:

      // Progress bar length
      $progress_bar_length = $this->options['max_output_width'] -
        strlen($progress_text) -  5 /* == strlen('%bar%') */;

      // Create progress bar
      $progress_bar = $this->_create_progress_bar($progress_info_done_part, $progress_bar_length);

      // Insert bar
      $progress_text = str_replace('%bar%', $progress_bar, $progress_text);
    }
    else
    {
      // Pad with spaces to $this->options['max_output_width']
      $progress_text = str_pad($progress_text, $this->options['max_output_width'], ' ', STR_PAD_RIGHT);
    }

    // Output progress text to console

    if ($this->progress_last_console_output != $progress_text) // Prevent output of the same text
    {
      // Erase progress
      $this->_erase_progress();

      // Print progress
      echo $progress_text;

      $this->progress_last_console_output = $progress_text;
    }
  }

  /**
   * Erase console progress
   */
  private function _erase_progress()
  {
    if (!is_null($this->progress_last_console_output))
    {
      echo "\x0D"; // CR (caret return)
      echo str_repeat(' ', strlen($this->progress_last_console_output));
      echo "\x0D";
    }
  }

  /**
   * Log progress
   *
   * @param float $progress_info_done_part
   */
  private function _log_progress($progress_info_done_part)
  {
    // Create progress text:

    $progress_text = $this->options['progress_log_format'];

    // Replace tags
    $progress_text = str_replace($this->progress_tag_names, $this->progress_tags, $progress_text);

    // Insert progress bar

    if (strstr($progress_text, '%bar%') !== false)
    {
      // Determine progress bar length

      $m = array();
      preg_match('/^.*%bar%.*$/m', $progress_text, $m);
      $progress_bar_length = $this->options['max_output_width'] - strlen($m[0]) -  5 /* == strlen('%bar%') */;

      // Create progress  bar
      $progress_bar = $this->_create_progress_bar($progress_info_done_part, $progress_bar_length);

      // Insert bar
      $progress_text = str_replace('%bar%', $progress_bar, $progress_text);
    }

    // Write to file

    if ($fp = @fopen($this->options['progress_file'], 'w'))
    {
      fwrite($fp, $progress_text);
      fclose($fp);
    }
    else
    {
      trigger_error("Failed opening progress file '{$this->options['progress_file']}'", E_USER_WARNING);
    }
  }

  /**
   * Create progress bar
   *
   * @param float $done_part
   * @param integer $length
   * @return string
   */
  private function _create_progress_bar($done_part, $length)
  {
    if ($length > 0)
    {
      $done_length = round($done_part * $length);
      return str_repeat('#', $done_length) . str_repeat('-', $length - $done_length);
    }
    else
    {
      return '';
    }
  }

  /**
   * Initialize options' default values
   * They are independent of options passed by user
   */
  protected function _init_default_options()
  {
    // Logging options
    $this->default_options['logging_default'] = self::MESSAGE_STATUS . self::MESSAGE_ERROR
      . self::MESSAGE_INFORMATION . self::LOG_OVERWRITE;

    // Verbocity options
    $this->default_options['verbocity_default'] = self::MESSAGE_STATUS . self::MESSAGE_ERROR
      . self::VERB_PROGRESS;

    // Log file
    $this->default_options['log_file'] = pathinfo($_SERVER['PHP_SELF'], PATHINFO_FILENAME) . '.log';

    // Progress file
    $this->default_options['progress_file'] = pathinfo($_SERVER['PHP_SELF'], PATHINFO_FILENAME) . '.progress';

    // Progress file format
    $this->default_options['progress_log_format'] = "%title%\n\n%item%/%total% [%bar%] %percent%\n\n" .
      "Speed (cur):  %speed_cur%\nSpeed (avg):  %speed_avg%\nTime elapsed:\t%time_passed%\nTime left:    ~ %eta%";
  }

  /**
   * Check if verbocity option is set
   * 
   * @param string $name
   * @return boolean 
   */
  private function _have_verbocity_flag($name)
  {
    return strstr($this->parameters['verbocity'], $name) !== false;
  }

  /**
   * Check if logging option is set
   *
   * @param string $name
   * @return boolean
   */
  private function _have_logging_flag($name)
  {
    return strstr($this->parameters['logging'], $name) !== false;
  }

  /**
   * Convert parameter value from string representation to php variable
   *
   * @param ? $arg_value
   * @param string $declared_parameter_name
   * @return number|string|boolean|array
   */
  private function _read_parameter_value($arg_value, $declared_parameter_name)
  {
    $declared_parameter = $this->declared_parameters[$declared_parameter_name];
    $type = $this->_get_declared_parameter_type($declared_parameter_name); // Get parameter type

    if (is_null($arg_value))
    {
      $arg_value = $declared_parameter['default'];
    }

    if ($type == self::PARAM_TYPE_INTEGER)
    {
      return intval($arg_value);
    }
    else if ($type == self::PARAM_TYPE_STRING)
    {
      return strval($arg_value);
    }
    else if ($type == self::PARAM_TYPE_BOOLEAN)
    {
      if ($arg_value === '')
      {
        // Boolean parameter without value ==> true.
        // Example: test.php boolean_val
        return true;
      }
      else
      {
        return CLIUtil_Utils::str2Bool($arg_value);
      }
    }
    else if ($type == self::PARAM_TYPE_ARRAY)
    {
      return CLIUtil_Utils::explodeString($arg_value, '+');
    }
    else if ($type == self::PARAM_TYPE_TIME_SEC) // Time in seconds
    {
      return $this->_time_sec_to_int($arg_value);
    }
    else
    {
      throw new Exception('Wrong parameter type for "' . $declared_parameter_name . '"', self::ERROR_MISC);
    }
  }

  /**
   * Converts PARAM_TYPE_TIME_SEC to integer number of seconds
   *
   * @param string $time_sec
   * @return integer
   */
  private function _time_sec_to_int($time_sec)
  {
    $time_val = 0;
    $time_unit = '';
    sscanf($time_sec, '%d%s', $time_val, $time_unit);

    $time_units_in_seconds = array('' => 1, 's' => 1, 'm' => 60,
      'h' => 60 * 60, 'd' => 60 * 60 * 24, 'w' => 60 * 60 * 24 * 7);
    $time_sec = $time_val * $time_units_in_seconds[strtolower($time_unit)];

    return $time_sec;
  }

  /**
   * Detects declared parameter type
   *
   * @param string $declared_parameter_name
   * @return integer
   */
  private function _get_declared_parameter_type($declared_parameter_name)
  {
    $declared_parameter = $this->declared_parameters[$declared_parameter_name];
    $type = $declared_parameter['type'];
    $default = $declared_parameter['default'];

    if ($declared_parameter['type'] == self::PARAM_TYPE_AUTO)
    {
      if (is_null($declared_parameter['default']))
      {
        throw new Exception('Unknown parameter type for "' . $declared_parameter_name . '"', self::ERROR_MISC);
      }
      else
      {
        if (is_integer($declared_parameter['default']))
        {
          return self::PARAM_TYPE_INTEGER;
        }
        else if (is_string($declared_parameter['default']))
        {
          return self::PARAM_TYPE_STRING;
        }
        else if (is_bool($declared_parameter['default']))
        {
          return self::PARAM_TYPE_BOOLEAN;
        }
        else if (is_array($declared_parameter['default']))
        {
          return self::PARAM_TYPE_ARRAY;
        }
      }
    }
    else
    {
      return $declared_parameter['type'];
    }
  }

  /**
   *  Reads command line arguments (arg1:val1 arg2:val2 ...)
   *
   * @return integer Number of arguments
   */
  private function _read_arguments()
  {
    global $_SERVER;

    $args = array();

    for ($i = 1; $i <= $_SERVER['argc'] - 1; $i++)
    {
      $c_arg = $_SERVER['argv'][$i];

      if (preg_match('/^(.*?):(.*?)$/', $c_arg, $regs))
      {
        $arg_name = $regs[1];
        $arg_val = $regs[2];
      }
      else
      {
        $arg_name = $c_arg;
        $arg_val = '';
      }

      $args[$arg_name] = $arg_val;
    }

    return $args;
  }
}

/**
 * Class for adding options to other classes
 *
 */

class CLIUtil_Storage implements ArrayAccess
{

  // Error code

  const ERROR_OK = 0;
  const ERROR_MISC = -1;

  /**
   * Data fields
   *
   * @var array
   */
  protected $data = array();

  /**
   * Constructor
   *
   * @param IHost $host Host object
   * @param array $default_data Default options array
   */
  public function __construct(array $default_data = array())
  {
    $this->data = $default_data;
  }

  /**
   * Get field or all fields
   *
   * @param string $field_name
   * @return mixed
   */
  public function get($field_name = null)
  {
    if (is_null($field_name))
    {
      return $this->data;
    }
    else
    {
      if (array_key_exists($field_name, $this->data))
      {
        return $this->data[$field_name];
      }
      else
      {
        throw new Exception("Data field '$field_name' doesn't exist", self::ERROR_MISC);
      }
    }
  }

  /**
   * Set field or multiple fields
   *
   * @param string|array $name
   * @param mixed $value
   * @return CLIUtil_Storage
   */
  public function set($name, $value = null)
  {
    if (is_array($name)) // set([array])
    {
      if (count($name) > 0)
      {
        foreach ($name as $k => $v)
        {
          $this->set($k, $v);
        }
      }
    }
    else // set(name, value)
    {
      if (array_key_exists($name, $this->data))
      {
        $this->data[$name] = $value;
      }
      else
      {
        throw new Exception("Data field '$name' doesn't exist", self::ERROR_MISC);
      }
    }

    return $this;
  }

  /**
   * Default property getter
   *
   * @param string $name
   * @return mixed
   */
  public function __get($name)
  {
    return $this->get($name);
  }

  /**
   * Default setter
   *
   * @param string $name
   * @param mixed $value
   * @return CLIUtil_Storage
   */
  public function __set($name, $value)
  {
    return $this->set($name, $value);
  }

  // <editor-fold defaultstate="collapsed" desc="Implementation of ArrayAcces interface">

  public function offsetExists($offset)
  {
    return array_key_exists($this->data, $offset);
  }

  public function offsetGet($offset)
  {
    return $this->get($offset);
  }

  public function offsetSet($offset, $value)
  {
    if ($offset === null)
    {
      throw new Exception('Option name not specified', self::ERROR_MISC);
    }
    else
    {
      $this->set($offset, $value);
    }
  }

  public function offsetUnset($offset)
  {
    throw new Exception("Can't unset option '$offset'", self::ERROR_MISC);
  }

  // </editor-fold>
}

/**
 * Utils
 */

class CLIUtil_Utils
{
  // Text align constants

  const TEXT_ALIGN_LEFT                   = 0x0000;
  const TEXT_ALIGN_RIGHT                  = 0x0001;
  const TEXT_ALIGN_CENTER                 = 0x0002;
  const TEXT_ALIGN_JUSTIFY                = 0x0004;
  const TEXT_ALIGN_FLAG_JUSTIFY_ALL_LINES = 0x0100; // Distribute all lines

  /**
   * Aligns text left, centered, right or justified
   *
   * @param string $text
   * @param integer $align
   * @param integer $width
   * @param string $newline Newline sequence
   * @param boolean $cut
   * @param integer $paragraph_sep_lines
   * @param integer $text_indent
   * @return ?|string
   */
  public static function textAlign($text, $align = self::TEXT_ALIGN_LEFT, $text_width = 76, $newline_str = "\n",
    $cut_words = true, $paragraph_sep_lines = 1, $paragraph_indent = 0)
  {
    // remove redundant spaces
    $text = trim(preg_replace('/ +/', ' ', $text));

    switch ($align & 0x0F)
    {
      case self::TEXT_ALIGN_LEFT:
      {
        // add paragraph indents
        if ($paragraph_indent > 0)
        {
          $indent       = str_repeat("\0", $paragraph_indent);
          $text         = $indent . str_replace($newline_str, $newline_str . $indent, $text);
        }

        $text = str_replace($newline_str, str_repeat($newline_str, $paragraph_sep_lines + 1), $text);
        $text = wordwrap($text, $text_width, $newline_str, $cut_words);
        return str_replace("\0", ' ', $text);
      }

      case self::TEXT_ALIGN_CENTER:
      case self::TEXT_ALIGN_RIGHT:
      {
        $text   = str_replace($newline_str, str_repeat($newline_str, $paragraph_sep_lines + 1), $text);
        $text   = wordwrap($text, $text_width, $newline_str, $cut_words);
        $lines  = explode($newline_str, $text);

        $pad_type = $align == self::TEXT_ALIGN_RIGHT ? STR_PAD_LEFT : STR_PAD_BOTH;

        for ($l = 0; $l < count($lines); $l++)
        {
          $line_len = strlen($lines[$l]);
          if ($line_len == 0) { continue; }
          $line_add = $text_width - $line_len;

          if ($line_add > 0)
          {
            $lines[$l] = str_pad($lines[$l], $text_width, ' ', $pad_type);
          }
        }

        return implode($newline_str, $lines);
      }

      case self::TEXT_ALIGN_JUSTIFY:
      {
        // split text into paragraphs
        $paragraphs = explode($newline_str, $text);

        for ($p = 0; $p < count($paragraphs); $p++)
        {
          // trim paragraph
          $paragraphs[$p] = trim($paragraphs[$p]);

          // add paragraph indents
          if ($paragraph_indent > 0)
          {
            $indent         = str_repeat("\0", $paragraph_indent);
            $paragraphs[$p] = $indent . str_replace($newline_str, $newline_str . $indent, $paragraphs[$p]);
            $nulls_added    = true;
          }

          // wrap paragraph words
          $paragraphs[$p] = wordwrap($paragraphs[$p], $text_width, $newline_str, $cut_words);

          // split paragraph into lines
          $paragraphs[$p] = explode($newline_str, $paragraphs[$p]);

          // last line index
          $pl_to = ($align & self::TEXT_ALIGN_FLAG_JUSTIFY_ALL_LINES) ? count($paragraphs[$p]) : count($paragraphs[$p]) - 1;

          for ($pl = 0; $pl < $pl_to; $pl++)
          {
            // spaces to be added
            $line_spaces_to_add   = $text_width - strlen($paragraphs[$p][$pl]);
            // split line
            $paragraphs[$p][$pl]  = explode(' ', $paragraphs[$p][$pl]);
            // number of words per line
            $line_word_count      = count($paragraphs[$p][$pl]);

            if ($line_word_count > 1 && $line_spaces_to_add > 0)
            {
              // spaces per each word (float)
              $line_spaces_per_word = $line_spaces_to_add / ($line_word_count - 1);
              $word_spaces_to_add   = 0;

              for ($w = 0; $w < $line_word_count - 1; $w++)
              {
                // (float) spaces to add
                $word_spaces_to_add += $line_spaces_per_word;
                // actual number of spaces to add (int)
                $word_spaces_to_add_int = (int) round($word_spaces_to_add);

                if ($word_spaces_to_add_int > 0)
                {
                  $paragraphs[$p][$pl][$w] .= str_repeat(' ', $word_spaces_to_add_int);
                  $word_spaces_to_add -= $word_spaces_to_add_int;
                }
              }
            }

            // restore line
            $paragraphs[$p][$pl] = implode(' ', $paragraphs[$p][$pl]);
          }

          // replace "\0" with spaces
          if ($nulls_added)
          {
            $paragraphs[$p][0] = str_replace("\0", ' ', $paragraphs[$p][0]);
          }

          // restore paragraph
          $paragraphs[$p] = implode($newline_str, $paragraphs[$p]);
        }

        // restore text
        $paragraphs = implode(str_repeat($newline_str, $paragraph_sep_lines + 1), $paragraphs);

        return $paragraphs;
      }
    }
  }

  /**
   * Converts string to boolean
   * @param string $string
   * @return boolean
   */
  public static function str2Bool($string)
  {
    $string = strtolower($string);

    if ($string == 'y' || $string == 'yes' || $string == 't' || $string == 'true')
    {
      return true;
    }
    else if ($string == 'n' || $string == 'no' || $string == 'f' || $string == 'false')
    {
      return false;
    }
    else if (is_numeric($string))
    {
      if (floatval($string) == 0)
      {
        return false;
      }
      else
      {
        return true;
      }
    }
    else
    {
      return false;
    }
  }

  /**
   * Splits string into the array of strings with quotes consideration
   *
   * @param str $str
   * @param char $delimiter
   * @return array
   * @author Misha Yurasov
   */
  public static function explodeString($input, $delimiter = ' ')
  {
    $q1 = false;  // single quote level
    $q2 = false;  // double quote level
    $c = '';      // current char
    $w = '';      // current word
    $j = 0;       // index counter
    $n = false;   // next word flag

    $len = strlen($input);

    for ($i = 0; $i < $len; $i++)
    {
      $c = $input{$i}; // current char

      switch ($c)
      {
        case "'":
        {
          if ($q2 == false)
          {
            $q1 = !$q1;
          }

          break;
        }

        case '"':
        {
          if ($q1 == false)
          {
            $q2 = !$q2;
          }

          break;
        }

        case $delimiter:
        {
          if (!($q1 || $q2))
          {
            $n = true;
            $c = '';
          }

          break;
        }
      }

      $w .= $c;

      if ($n || $i == $len - 1)
      {
        if ($w{0} == "'" || $w{0} == '"')
        {
          $w = trim($w, $w{0});
        }

        $ww[$j++] = $w;
        $w = '';
        $n = false;
      }
    }

    return $ww;
  }

  /**
   * Indent or un-indent text
   *
   * @param string $str
   * @param string $indent_str
   * @param integer $indentation
   * @param string $newline_str
   * @return string
   */
  public static function textIndent ($str, $indent_str, $indentation, $newline_str = "\n")
  {
    if ($indentation != 0)
    {
      $lines = explode($newline_str, $str);

      if ($indentation < 0)
      {
        for ($i = 0; $i < count($lines); $i++)
        {
          // Find leading tabs

          $remove_indents = 0;

          for ($ii = 0; $ii < strlen($lines[$i]); $ii += strlen($indent_str))
          {
            if (substr($lines[$i], $ii, strlen($indent_str)) != $indent_str)
            {
              break;
            }
            else if ($remove_indents >= -$indentation)
            {
              break;
            }
            else
            {
              $remove_indents++;
            }
          }

          // Remove leading tabs
          $lines[$i] = substr($lines[$i], $remove_indents * strlen($indent_str));
        }
      }
      else
      {
        // == repeat(tab) + line
        $indent_str_full = str_repeat($indent_str, max($indentation, 0));

        for ($i = 0; $i < count($lines); $i++)
        {
          $lines[$i] = $indent_str_full . $lines[$i];
        }
      }

      $str = implode($newline_str, $lines);
    }

    return $str;
  }

  /**
   * Converts time in seconds to human-redable string
   *
   * @param float $seconds
   * @param integer $precision
   * @param boolean $strip_empty_units
   * @param integer $units_naming_level
   * @param boolean $two_digit_hms
   * @return string
   */
  public static function formatTime(
    $seconds, $precision = 0, $strip_empty_units = true,
    $units_naming_level = 3, $two_digit_hms = false)
  {
    $result = '';
    $prev_entry_present = false;
    $seconds = round($seconds, $precision);

    // Units' names

    switch ($units_naming_level)
    {
      case 0:
      {
        $units = array(
          'd' => 'd',
          'dd' => 'd',
          'w' => 'w',
          'ww' => 'w'
        );

        break;
      }

      case 1:
      {
        $units = array(
          's' => 's',
          'ss' => 's',
          'm' => 'm',
          'mm' => 'm',
          'h' => 'h',
          'hh' => 'h',
          'd' => 'd',
          'dd' => 'd',
          'w' => 'w',
          'ww' => 'w'
        );

        break;
      }

      case 2:
      {
        $units = array(
          's' => ' sec',
          'ss' => ' sec',
          'm' => ' min',
          'mm' => ' min',
          'h' => ' hr',
          'hh' => ' hr',
          'd' => ' dy',
          'dd' => ' dy',
          'w' => ' wk',
          'ww' => ' wk'
        );

        break;
      }

      case 3:
      {
        $units = array(
          's' => ' second',
          'ss' => ' seconds',
          'm' => ' minute',
          'mm' => ' minutes',
          'h' => ' hour',
          'hh' => ' hours',
          'd' => ' day',
          'dd' => ' days',
          'w' => ' week',
          'ww' => ' weeks'
        );

        break;
      }
    }

    // Seconds

    $seconds_fraction = fmod($seconds, 60);

    if ($seconds_fraction >= 0 || !$strip_empty_units || $seconds < 1)
    {
      $result = $units_naming_level > 0

      ? (($two_digit_hms && $seconds_fraction < 10 && $seconds >= 60 ? '0' : '' /* zero padding */)
          . sprintf('%.' . $precision . 'f%s',
            $seconds_fraction, (floor($seconds_fraction) % 10 != 1)
              || ($precision > 0) ? $units['ss'] : $units['s']))

        : ((($two_digit_hms || $seconds_fraction < 10) && $seconds >= 60 ? '0' : '' /* zero padding */)
          . sprintf('%.' . $precision . 'f', $seconds_fraction));

      $prev_entry_present = true;
    }

    // Minutes

    if ($seconds >= 60)
    {
      $minutes = floor($seconds / 60) % 60;
      $prev_entry_present = $prev_entry_present || $seconds > 0;

      if ($prev_entry_present || $minutes > 0)
      {
        if ($seconds < 10 && $units_naming_level == 0)
        {
          $result = '0' . $result;
        }

        $result = $units_naming_level > 0

           ? sprintf($two_digit_hms && $seconds >= 3600 ? '%02d%s' : '%d%s',
             $minutes, $minutes % 10 != 1 ? $units['mm'] : $units['m']) . ($prev_entry_present ? ' ' : '') . $result

          : sprintf('%02d',
            $minutes) . ($prev_entry_present ? ':' : '') . $result ;
      }
    }

    // Hours

    if ($seconds >= 3600)
    {
      $hours = floor($seconds / 3600) % 24;
      $prev_entry_present = $prev_entry_present || $minutes > 0;

      if ($prev_entry_present || $hours > 0)
      {
        $result = $units_naming_level > 0

          ? sprintf($two_digit_hms && $seconds >= 86400 ? '%02d%s' : '%d%s',
            $hours, $hours % 10 != 1 ? $units['hh'] : $units['h']) . ($prev_entry_present ? ' ' : '') . $result

          : sprintf('%02d',
            $hours) . ($prev_entry_present ? ':' : '') . $result;
      }
    }

    // Days

    if ($seconds >= 86400)
    {
      $days = floor($seconds / 86400) % 7;
      $prev_entry_present = $prev_entry_present || $hours > 0;
      //
      if ($prev_entry_present || $days > 0)
      {
        $result = sprintf('%d%s',
          $days, $days % 10 != 1 ? $units['dd'] : $units['d']) . ($prev_entry_present ? ' ' : '') . $result;
      }
    }

    // Weeks

    if ($seconds >= 604800)
    {
      $weeks = floor($seconds / 604800);
      $prev_entry_present = $prev_entry_present || $days > 0;
      //
      if ($prev_entry_present || $weeks > 0)
      {
        $result = sprintf('%d%s',
          $weeks, $weeks % 10 != 1 ? $units['ww'] : $units['w']) . ($prev_entry_present ? ' ' : '') . $result;
      }
    }

    return $result;
  }
}