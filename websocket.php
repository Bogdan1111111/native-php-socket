<?php
ignore_user_abort(true);
set_time_limit(0);
ob_implicit_flush();

$connections = [];

if (!($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
    echo 'Сокет не создан. Причина: ' . socket_strerror(socket_last_error());
}
if (!socket_bind($sock, 'localhost', 8080)) {
    echo 'Сокет не привязан. Причина: ' . socket_strerror(socket_last_error());
}

if (!socket_listen($sock)) {
    echo 'Сокет не прослушивается. Причина: ' . socket_strerror(socket_last_error());
}

socket_set_nonblock($sock);

echo 'Ok. Socket created!';

while (true) {
    if ($connection = socket_accept($sock)) {
      $headers = socket_read($connection,1024);
      $parts = explode('Sec-WebSocket-Key:',$headers);
      $secWSKey = trim(explode(PHP_EOL,$parts[1])[0]);
      $secWSAccept = base64_encode(sha1($secWSKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));

      $answer = [
        'HTTP/1.1 101 Switching Protocols',
        'Upgrade: websocket',
        'Connection: Upgrade',
        'Sec-WebSocket-Accept: ' . $secWSAccept,
        'Sec-WebSocket-Version: 13'
      ];

      if (stripos($headers,'name=') !== false) {
        $parts = explode('name=',$headers,2);
        $name = explode(' ',$parts[1])[0];
      }
      $name = isset($name) ? $name : 'Anonymous' . count($connections);

      socket_write($connection, implode("\r\n",$answer) . "\r\n\r\n");
      socket_write($connection,encodeToFrame('Server: Hello! Welcome to Chat, ' . $name));

      if (!empty($connections)) {
        foreach ($connections as $connect) {
          socket_write($connect->connection,encodeToFrame('Server: New User(' . $name . ') in Chat!'));
        }
      }

      $connections[] = (object) [
        'connection' => $connection,
        'name' => $name
      ];
    }

    if (!empty($connections)) {
      foreach ($connections as $connect) {
        $message = frameDecode(socket_read($connect->connection,1024000));

        if ($message === 'break') {
          break 2;
        }

        if (!empty($message)) {//echo $message;
          foreach ($connections as $c) {
            socket_write($c->connection,encodeToFrame($connect->name . ': ' . $message));
          }

          $message = '';
        }
      }
    }
}

function frameDecode($frame)
{
  $firstByteToBits = sprintf('%08b', ord($frame[0]));
  $secondByteToBits = sprintf('%08b', ord($frame[1]));

  $opcod = bindec(substr($firstByteToBits,4));

  if ((int)$secondByteToBits[0] === 0 || $firstByteToBits[0] === 0 || $opcod !== 1) {
    return '';
  }

  $bodyLenght = bindec(substr($secondByteToBits,1));

  if ($bodyLenght < 126) {
    $bodyLenght = $bodyLenght;
    $maskKey = substr($frame,2,4);
    $body = substr($frame,6,$bodyLenght);
  } elseif ($bodyLenght === 126) {
    $bodyLenght = sprintf('%16b',substr($frame,2,2));
    $maskKey = substr($frame,4,4);
    $body = substr($frame,8,$bodyLenght);
  } else {
    $bodyLenght = sprintf('%64b',substr($frame,2,8));
    $maskKey = substr($frame,10,4);
    $body = substr($frame,14,$bodyLenght);
  }

  $i = 0;
  $unmaskedBody = '';

  while ($i < $bodyLenght/4) {
    $unmaskedBody .= substr($body,4*$i,4) ^ $maskKey;
    $i++;
  }

    return trim($unmaskedBody);
}

function encodeToFrame($content)
{
  $opcodInBits = sprintf('%04b', 1);
  $bodyLenght = strlen($content);
  $bodyLenghtInSecondByte = sprintf('%07b',$bodyLenght);
  $extendedLenght = '';

  if ($bodyLenght > 125) {
    if ($bodyLenght < 65536) {
      $bodyLenghtInSecondByte = sprintf('%07b',126);
      $extendedLenght = sprintf('%32b',$bodyLenght);
    } elseif ($bodyLenght > 65535 && $bodyLenght < 4294967296) {
      $bodyLenghtInSecondByte = sprintf('%07b',127);
      $extendedLenght = sprintf('%64b',$bodyLenght);
    } else {
      return '';
    }
  }

  $firstByte = chr(bindec('1000' . $opcodInBits));
  $secondByte = chr(bindec('1' . $bodyLenghtInSecondByte));

  return $firstByte . $secondByte . $content;
}

socket_close($sock);
