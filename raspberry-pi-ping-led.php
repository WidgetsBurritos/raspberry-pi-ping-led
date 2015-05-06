<?php
require 'vendor/autoload.php';

use PhpGpio\Gpio;

/**
* Pings a host
*
* from http://www.stackoverflow.com/questions/20467432/php-how-to-ping-a-server-without-system-or-exec
**/
function ping ($host, $timeout =1) {
	$package = "\x08\x00\x7d\x4b\x00\x00\x00\x00PingHost";
	$socket = @socket_create(AF_INET, SOCK_RAW, 1);
	@socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));
	@socket_connect($socket, $host, null);
	$ts = microtime(true);
	@socket_send($socket, $package, strlen($package), 0);
	if (@socket_read($socket, 255))
		$result = microtime(true) - $ts;
	else
		$result = false;

	return $result;
}

/**
* Begins a ping loop
**/
function ping_loop($settings) {
    // default settings
    $default_settings = [
	'padding_size' => 300,
	'max_outages' => 30,
	'failed_packets_in_outage' => 3,
	'color_background' => NCURSES_COLOR_BLACK,
	'color_good' => NCURSES_COLOR_GREEN,
	'color_bad' => NCURSES_COLOR_RED,
	'color_neutral' => NCURSES_COLOR_WHITE,
	'lock_file' => '/var/lock/raspi-ping.lock',
    ];
    $settings = array_merge($default_settings, $settings);

    // initalize variables
    $dropped_packets = 0;
    $total_packets = 0;
    $last_dropped = false;
    $consecutive_drops = 0;
    $outage_start = null;
    $outage_end = null;
    $outage_ct = 0;
    $outages = [];

    // setup GPIO
    $gpio = new GPIO();
    if(!in_array($settings['pin'], $gpio->getHackablePins())){
         throw new Exception("{$settings['pin']} is not a hackable gpio pin number");
    }
    $gpio->setup($settings['pin'], "out");

    // Establish ncurses settings
    ncurses_init();
    ncurses_curs_set(0);
    ncurses_clear();
    if (ncurses_has_colors()) {
	ncurses_start_color();
	ncurses_init_pair(1, $settings['color_good'], $settings['color_background']);
	ncurses_init_pair(2, $settings['color_bad'], $settings['color_background']);
	ncurses_init_pair(3, $settings['color_neutral'], $settings['color_background']);
    }
    $full_screen = ncurses_newwin(0,0,0,0);
    ncurses_refresh();

    // loop
    touch($settings['lock_file']);
    while(file_exists($settings['lock_file'])) {
	$total_packets = bcadd($total_packets, 1);	
	// Behavior if ping was successful
	if ($result = ping($settings['ipaddress'])) {
		if ($last_dropped) {
			$outage_end = date("Y-m-d h:i:s");
			$outages[0][1] = $outage_end;
		}
		$gpio->output($settings['pin'], 0);
		$percent = bcdiv($dropped_packets, $total_packets, 5);
		if (ncurses_has_colors()) {
			ncurses_color_set(1);
		}
		ncurses_mvaddstr(0,1,str_pad(sprintf("Ping Time : %0.5f\tDropped Packet Count : %s\tDropped Percentage : %0.4f%%\tTotal Outages: %s", $result, $dropped_packets, $percent, $outage_ct), $settings['padding_size']));
		$last_dropped = false;
		$consecutive_drops = 0;
		$outage_start = $outage_end = null;
	// Behavior if ping failed
	} else {
		if ($last_dropped) {
			$outage_start = date("Y-m-d h:i:s");
		}
		$dropped_packets = bcadd($dropped_packets, 1);
		$gpio->output($settings['pin'], 1);
		$consecutive_drops = bcadd($consecutive_drops, 1);
		if (intval($consecutive_drops) == $settings['failed_packets_in_outage']) {
			$outage_ct = bcadd($outage_ct, 1);
			array_unshift($outages, [$outage_start, null]);
			if (sizeof($outages) > $settings['max_outages']) array_pop($outages);
		}
		$last_dropped = true;
		$percent = bcdiv($dropped_packets, $total_packets, 5);
		if (ncurses_has_colors()) {
			ncurses_color_set(2);
		}
		ncurses_mvaddstr(0,1, str_pad(sprintf("DROPPED PACKET DETECTED\tDropped Packet Count: %s\tConsecutive Drops: %s\tDropped Percentage : %0.4f%%\tTotal Outages: %s", $dropped_packets, $consecutive_drops, $percent, $outage_ct), $settings['padding_size']));
	}
	// Output information about Outages
	if (ncurses_has_colors()) {
		ncurses_color_set(3);
	}
	if (sizeof($outages) > 0) {
	    ncurses_mvaddstr(4,3, str_pad('Recent Outages:', $settings['padding_size']));
	    for ($i=0; $i<sizeof($outages); $i++) {
		if (!isset($outages[$i]) || !is_array($outages[$i]) || empty($outages[$i]) || empty($outages[$i][0])) continue;
		list ($start, $end) = $outages[$i];	
		if ($outages[$i][1] == null) {
			$diff = "";
			$end = "[in progress]";
		} else {
			$diff = strtotime($outages[$i][1])-strtotime($outages[$i][0]). ' seconds';
			$end = $outages[$i][1];
		}
		ncurses_mvaddstr(5+$i,5, str_pad(sprintf("%s\t%s\t\t\t%s", $start, $end, $diff), $settings['padding_size']));
	    }
	}
	ncurses_refresh();
	sleep(1);
    }
    ncurses_end();
}


try {
	if ('cli' != PHP_SAPI) throw new Exception("This script must be run using php-cli"); 
	if ($_SERVER['USER'] != "root") throw new Exception("Usage: sudo php ping_check.php IPADDRESS OUTPIN");
	if (empty($argv[1]) || empty($argv[2])) throw new Exception("Usage: sudo php ping_check.php IPADDRESS OUTPIN");
	$settings = [
		'ipaddress' => $argv[1],
		'pin' => (int)$argv[2],
	];
	
	
	ping_loop($settings);
} catch (Exception $e) {
	die($e->getMessage()."\n");
}
