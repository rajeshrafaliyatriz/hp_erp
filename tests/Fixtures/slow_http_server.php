<?php

/**
 * A tiny deliberately misbehaving HTTP server for SafeUrlFetcherDeadlineTest. TEST FIXTURE ONLY.
 *
 * It binds 127.0.0.1 on a free port, prints "PORT <n>" on stdout, serves several connections at once (stream_select), and
 * exits by itself after <lifetime> seconds so a crashed test run cannot leave it behind. It never contacts anything.
 *
 *   php slow_http_server.php <robots_delay_ms> <lifetime_seconds>
 *
 * Routes:
 *   /ok              200 text/html, a normal page
 *   /echo-host       200 text/html whose body is the Host header it received (proves which name the client sent)
 *   /hang            accepts and never answers
 *   /drip            200 headers at once, then one body byte every 200 ms for 20 s
 *   /headers-drip    a status line, then one header line every 200 ms and never the end of the headers
 *   /big             200 headers, then a 3 MB body as fast as the client reads
 *   /non-html        200 application/pdf
 *   /hop/<n>         after 1 s, 302 to /hop/<n-1>; /hop/0 is a normal page
 *   /to-metadata     302 to the cloud metadata address
 *   /to-loopback     302 to http://127.0.0.1/ok
 *   /to-private      302 to http://10.0.0.5/
 *   /loop            302 to itself
 *   /robots.txt      200 text/plain "allow everything", after <robots_delay_ms>
 */

$robotsDelay = max(0, (int) ($argv[1] ?? 0)) / 1000;
$lifetime = max(5, (int) ($argv[2] ?? 60));

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(1);
}
$port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
fwrite(STDOUT, "PORT $port\n");
fflush(STDOUT);

$page = static fn (string $body, string $type = 'text/html'): string => "HTTP/1.1 200 OK\r\nContent-Type: $type\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
$text = '<html><head><title>Acme</title></head><body><p>' . str_repeat('Acme builds school software for districts across the region. ', 4) . '</p></body></html>';

$clients = [];
$end = microtime(true) + $lifetime;

while (microtime(true) < $end) {
    $read = [$server];
    $write = [];
    foreach ($clients as $id => $c) {
        if (! $c['routed']) {
            $read[] = $c['sock'];
        }
        if ($c['out'] !== '') {
            $write[] = $c['sock'];
        }
    }
    $except = null;
    if (@stream_select($read, $write, $except, 0, 40000) === false) {
        break;
    }

    foreach ($read as $sock) {
        if ($sock === $server) {
            $conn = @stream_socket_accept($server, 0);
            if ($conn) {
                stream_set_blocking($conn, false);
                $clients[(int) $conn] = ['sock' => $conn, 'in' => '', 'out' => '', 'routed' => false, 'events' => [], 'closeWhenDrained' => false];
            }

            continue;
        }
        $id = (int) $sock;
        $data = @fread($sock, 8192);
        if ($data === '' || $data === false) {
            if (feof($sock)) {
                fclose($sock);
                unset($clients[$id]);
            }

            continue;
        }
        $clients[$id]['in'] .= $data;
        if (! str_contains($clients[$id]['in'], "\r\n\r\n")) {
            continue;
        }

        $clients[$id]['routed'] = true;
        $request = $clients[$id]['in'];
        preg_match('#^GET (\S+) HTTP#', $request, $m);
        $target = $m[1] ?? '/';
        preg_match('/^Host:\s*(.+?)\r?$/mi', $request, $h);
        $host = trim($h[1] ?? "127.0.0.1:$port");
        $path = (string) parse_url($target, PHP_URL_PATH);
        $now = microtime(true);
        $redirect = static fn (string $to): string => "HTTP/1.1 302 Found\r\nLocation: $to\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

        $c = &$clients[$id];
        if ($path === '/ok') {
            $c['out'] = $page($text);
            $c['closeWhenDrained'] = true;
        } elseif ($path === '/echo-host') {
            $c['out'] = $page('<html><body><p>host=' . $host . ' ' . str_repeat('padding text for the readable minimum. ', 3) . '</p></body></html>');
            $c['closeWhenDrained'] = true;
        } elseif ($path === '/hang') {
            // nothing: the client waits
        } elseif ($path === '/drip') {
            $c['out'] = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n";
            for ($i = 1; $i <= 100; $i++) {
                $c['events'][] = [$now + 0.2 * $i, 'a'];
            }
        } elseif ($path === '/headers-drip') {
            $c['out'] = "HTTP/1.1 200 OK\r\n";
            for ($i = 1; $i <= 100; $i++) {
                $c['events'][] = [$now + 0.2 * $i, "X-Slow-$i: 1\r\n"];
            }
        } elseif ($path === '/big') {
            $c['out'] = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nConnection: close\r\n\r\n" . str_repeat('a', 3 * 1024 * 1024);
            $c['closeWhenDrained'] = true;
        } elseif ($path === '/non-html') {
            $c['out'] = $page('%PDF-1.4 not a page', 'application/pdf');
            $c['closeWhenDrained'] = true;
        } elseif (preg_match('#^/hop/(\d+)$#', $path, $hop)) {
            $n = (int) $hop[1];
            $c['events'][] = [$now + ($n === 0 ? 0 : 1.0), $n === 0 ? $page($text) : $redirect('http://' . $host . '/hop/' . ($n - 1))];
            $c['closeWhenDrained'] = true;
        } elseif ($path === '/to-metadata') {
            $c['out'] = $redirect('http://169.254.169.254/latest/meta-data/');
            $c['closeWhenDrained'] = true;
        } elseif ($path === '/to-loopback') {
            $c['out'] = $redirect('http://127.0.0.1/ok');   // standard port, so it is the ADDRESS rule that must refuse it
            $c['closeWhenDrained'] = true;
        } elseif ($path === '/to-private') {
            $c['out'] = $redirect('http://10.0.0.5/');
            $c['closeWhenDrained'] = true;
        } elseif ($path === '/loop') {
            $c['out'] = $redirect('http://' . $host . '/loop');
            $c['closeWhenDrained'] = true;
        } elseif ($path === '/robots.txt') {
            $c['events'][] = [$now + $robotsDelay, $page("User-agent: *\nAllow: /\n", 'text/plain')];
            $c['closeWhenDrained'] = true;
        } else {
            $c['out'] = "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
            $c['closeWhenDrained'] = true;
        }
        unset($c);
    }

    $now = microtime(true);
    foreach ($clients as $id => &$c) {
        foreach ($c['events'] as $k => [$at, $bytes]) {
            if ($at <= $now) {
                $c['out'] .= $bytes;
                unset($c['events'][$k]);
            }
        }
    }
    unset($c);

    foreach ($write as $sock) {
        $id = (int) $sock;
        if (! isset($clients[$id]) || $clients[$id]['out'] === '') {
            continue;
        }
        $n = @fwrite($sock, $clients[$id]['out']);
        if ($n === false) {
            fclose($sock);
            unset($clients[$id]);

            continue;
        }
        $clients[$id]['out'] = (string) substr($clients[$id]['out'], $n);
    }

    foreach ($clients as $id => $c) {
        if ($c['routed'] && $c['out'] === '' && $c['events'] === [] && $c['closeWhenDrained']) {
            fclose($c['sock']);
            unset($clients[$id]);
        } elseif ($c['routed'] && ! is_resource($c['sock'])) {
            unset($clients[$id]);
        } elseif ($c['routed'] && @feof($c['sock'])) {
            fclose($c['sock']);
            unset($clients[$id]);
        }
    }
}
