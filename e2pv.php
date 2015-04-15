<?php
/*
 * Copyright (c) 2015 Otto Moerbeek <otto@drijf.net>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

require_once 'config.php';
// Example to put in config.php:
// define('IDCOUNT', 4);
// define('APIKEY', 'PVOutput hex api key');
// define('SYSTEMID', 'PVOutput system id');

if (!defined('LIFETIME'))
  define('LIFETIME', 1);

function report($msg) {
  echo date('Ymd-H:i:s') . ' ' . $msg . PHP_EOL;
}

function fatal($msg) {
  report($msg . ': ' . socket_strerror(socket_last_error()));
  exit(1);
}

$total = array();
$last = 0;
$lastkeepalive = 0;

function submit($total, $systemid) {
  $e = 0.0;
  $p = 0.0;
  $temp = 0.0;
  $volt = 0.0;
  foreach ($total as $t) {
    $e += $t['Energy'];
    $p += (double)$t['Power'] / $t['Count'];
    $temp += $t['Temperature'];
    $volt += $t['Volt'];
  }
  $temp /= count($total);
  $volt /= count($total);
  $p = round($p);

  if (LIFETIME)
    report(sprintf('=> PVOutput v1=%dWh v2=%dW v5=%.1fC v6=%.1fV',
      $e, $p, $temp, $volt));
  else
    report(sprintf('=> PVOutput v2=%dW v5=%.1fC v6=%.1fV', $p, $temp, $volt));
  $time = time();
  $data = array('d' => strftime('%Y%m%d', $time),
    't' => strftime('%H:%M', $time),
    'v2' => $p,
    'v5' => $temp,
    'v6' => $volt
  );
  if (LIFETIME) {
    $data['v1'] = $e;
    $data['c1'] = 1;
  }
  $headers = "Content-type: application/x-www-form-urlencoded\r\n" .
    'X-Pvoutput-Apikey: ' . APIKEY . "\r\n" .
    'X-Pvoutput-SystemId: ' . $systemid . "\r\n";
  $url = 'http://pvoutput.org/service/r2/addstatus.jsp';
  
  $data = http_build_query($data, '', '&');
  $ctx = array('http' => array(
    'method' => 'POST',
    'header' => $headers,
    'content' => $data));
  $context = stream_context_create($ctx);
  $fp = fopen($url, 'r', false, $context);
  if (!$fp)
    report('POST failed, check your APIKEY and SYSTEMID');
  else {
    $reply = fread($fp, 100);
    report('PVOutput replies: ' . $reply);
    fclose($fp);
  }
}

$buf = '';

function reader($socket) {
  global $buf;
  while (true) {
    $pos = strpos($buf, "\r");
    if ($pos === false) {
      $str = socket_read($socket, 128, PHP_NORMAL_READ);
      if ($str === false || strlen($str) == 0)
        return false;
      $buf .= $str;
      continue;
    } else {
      $str = substr($buf, 0, $pos + 1);
      $buf = substr($buf, $pos + 2);
      return $str;
    }
  }
}

$link = false;

function submit_mysql($v, $LifeWh) {
  global $link;

  if (!$link) {
    $link = mysqli_connect(MYSQLHOST, MYSQLUSER, MYSQLPASSWORD, MYSQLDB,
      MYSQLPORT);
  }
  if (!$link) {
    report('Cannot connect to MYSQL ' . mysqli_connect_error());
    return;
  }

  $query = 'INSERT INTO enecsys(' .
    'id, wh, dcpower, dccurrent, efficiency, acfreq, acvolt, temp) VALUES(' .
    '%d, %d, %d, %f, %f, %d, %d, %d)';
  $q = sprintf($query,
    mysqli_real_escape_string($link, $v['IDDec']),
    mysqli_real_escape_string($link, $LifeWh),
    mysqli_real_escape_string($link, $v['DCPower']),
    mysqli_real_escape_string($link, $v['DCCurrent']),
    mysqli_real_escape_string($link, $v['Efficiency']),
    mysqli_real_escape_string($link, $v['ACFreq']),
    mysqli_real_escape_string($link, $v['ACVolt']),
    mysqli_real_escape_string($link, $v['Temperature']));

  if (!mysqli_query($link, $q)) {
   report('MYSQL insert failed: ' . mysqli_error($link));
   mysqli_close($link);
   $link = false;
  }
}

function process($socket) {
  global $total, $last, $lastkeepalive, $systemid;

  while (true) {
    $str = reader($socket);
    if ($str === false) {
        return;
    }
    if ($lastkeepalive < time() - 200) {
      if (socket_write($socket, "0E0000000000cgAD83\r") === false)
        return;
      $lastkeepalive = time();
      //report('send keepalive');
    }
    $str = str_replace(array("\n", "\r"), "", $str);
    //report($str);
    $pos = strpos($str, 'WS');
    if ($pos !== false) {
        $sub = substr($str, $pos + 3);
        $sub = str_replace(array('-', '_' , '*'), array('+', '/' ,'='), $sub);
        //report(strlen($sub) . ' ' . $sub);
        $bin = base64_decode($sub);
        if (strlen($bin) != 42)
          continue;

        //echo bin2hex($bin) . PHP_EOL;
        $v = unpack('VIDDec/c17dummy/nErrorState/nDCCurrent/nDCPower/' .
           'nEfficiency/cACFreq/nACVolt/cTemperature/nWh/nkWh', $bin);
        $v['DCCurrent'] *= 0.025;
        $v['Efficiency'] *= 0.001;
        $LifeWh = $v['kWh'] * 1000 + $v['Wh'];
        $ACpower = round($v['DCPower'] * $v['Efficiency'], 2);
        $DCVolt = round($v['DCPower'] / $v['DCCurrent'], 2);

        $id = $v['IDDec'];
        $time = time();
        $total[$id]['TS'] = $time;
        $total[$id]['Energy'] = $LifeWh;
        if (!isset($total[$id]['Power'])) {
          $total[$id]['Power'] = 0;
          $total[$id]['Count'] = 0;
        }
        $total[$id]['Count']++;
        $total[$id]['Power'] += $v['DCPower'];
        $total[$id]['Volt'] = $v['ACVolt'];
        $total[$id]['Temperature'] = $v['Temperature'];

        // Clear stale entries
        foreach ($total as $key => $t) {
          if ($total[$key]['TS'] < $time - 3600)
            unset($total[$key]);
        }

        printf('%s DC=%3dW %5.2fV %4.2fA AC=%3dV %6.2fW E=%4.2f T=%2d ' .
          'E=%x L=%.3fkWh' .  PHP_EOL,
          $id, $v['DCPower'], $DCVolt, $v['DCCurrent'],
          $v['ACVolt'], $ACpower,
          $v['Efficiency'], $v['Temperature'], $v['ErrorState'],
          $LifeWh / 1000);
        if (MODE == 'SPLIT') {
          if (!isset($total[$id]['TS']) || $total[$id]['TS'] < $time - 600) {
            submit(array($total[$id]), $systemid[$id]);
            $total[$id]['Power'] = 0;
            $total[$id]['Count'] = 0;
          }
        } else {
          if (count($total) != IDCOUNT) {
            report('Expecing IDCOUNT=' . IDCOUNT . ' IDs, seen ' .
              count($total) . ' IDs');
          }
          if ($last < $time - 600 && count($total) == IDCOUNT) {
            submit($total, SYSTEMID);
            $last = $time;
            foreach ($total as $k => $t) {
              $total[$k]['Power'] = 0;
              $total[$k]['Count'] = 0;
            }
          }
        }
        if (defined('MYSQLDB'))
          submit_mysql($v, $LifeWh);
    }
  }
}

function setup() {
  $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  if ($socket === false)
    fatal('socket_create');
  socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
  $ok = socket_bind($socket, '0.0.0.0', 5040);
  if (!$ok) 
    fatal('socket_bind');
  $ok = socket_listen($socket, 1);
  if (!$ok)
    fatal('socket_listen');
  return $socket;
}

function loop($socket) {
  $errcount = 0;
  while (true) {
    $client = socket_accept($socket);
    if (!$client) {
      report('Socket_accept: ' . socket_strerror(socket_last_error()));
      if (++$errcount > 100)
        fatal('Too many socket_accept errors in a row');
      else
        continue;
    }
    $errcount = 0;
    socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO,
      array('sec' => 90, 'usec' => 0));
    socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);
    socket_getpeername($client, $peer);
    report('Accepted connection from ' . $peer);
    process($client);
    socket_close($client);
    report('Connection closed'); 
  }
}

if (MODE == 'SPLIT' && count($systemid) != IDCOUNT) {
  report('In SPLIT mode, define IDCOUNT systemid mappings');
  exit(1);
}
  
$socket = setup();
loop($socket);
socket_close($socket);

?>        
