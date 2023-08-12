<?php

function parse_env($envFilePath)
{
    if (!is_readable($envFilePath)) {
        throw new RuntimeException('cannot read file: ' . $envFilePath);
    }
    foreach (file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$key, $val] = explode('=', $line, 2);
        $val = trim($val, "'");
        if($key === 'IFS' || $key === "'") {
            continue;
        }
        $l = "$key=$val";
        echo "env line: ", $l, PHP_EOL;
        $_ENV[$key] = $val;
        putenv($l);
    }
}

function exec_cmd($cmd, &$stdout=null, &$stderr=null): int {
    $proc = proc_open($cmd,[
        1 => ['pipe','w'],
        2 => ['pipe','w'],
    ],$pipes, null, $_ENV);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return proc_close($proc);
}
