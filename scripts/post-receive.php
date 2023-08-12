<?php

const REF_NAME = 'refs/heads/master';
const LOCK_RETRY = 100;
const LOCK_WAIT = 3;

function parse_env($envFilePath)
{
    if (!is_readable($envFilePath)) {
        throw new RuntimeException('cannot read file: ' . $envFilePath);
    }
    foreach (file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$key, $val] = explode('=', $line, 2);
        $val = trim($val, "'");
        $l = "$key=$val";
        echo "env line: ", $l, PHP_EOL;
        putenv($l);
    }
}

parse_env("/var/kit/env");

$PATH_KIT = getenv('PATH_KIT') ?: '/var/kit';
$PATH_PVC = getenv('PATH_PVC') ?: $PATH_KIT . '/pvc';
$PATH_REPO = getenv('PATH_PVC') ?: $PATH_PVC . '/kit.git';
$PATH_CLONE = $PATH_KIT . '/clone';

$lockN = 0;
do {
    if (!is_dir($PATH_CLONE)) {
        mkdir($PATH_CLONE);
        break;
    }
    if ($lockN >= LOCK_RETRY) {
        fwrite(STDERR, "waiting on lock to clear timed out" . PHP_EOL);
        exit(1);
    }
    echo "another process is already running", PHP_EOL;
    sleep(LOCK_WAIT);
} while ($lockN++ < LOCK_RETRY);


$execCmd = function ($cmd) {
    echo "exec: [$cmd]", PHP_EOL;
    echo "result>", PHP_EOL;
    $code = 0;
    passthru($cmd, $code);
    if ($code <> 0) {
        throw new RuntimeException('failed to execute: ' . $cmd);
    }
    echo "<result", PHP_EOL;
};

$execCmd('echo whoami $(whoami)');
$execCmd('echo KUBECONFIG $KUBECONFIG');
$execCmd('echo PATH_REPO $PATH_REPO');

$error = null;
try {
    $stdin = fgets(STDIN);

    file_put_contents($PATH_KIT . "/post-receive.txt", $stdin);

    $commitMsg = '';
    $changedFiles = [];
    $oldRef = $newRef = $refName = '';

    foreach (explode("\n", $stdin) as $line) {
        $line = trim($line);
        if (strlen($line) < 5) {
            continue;
        }
        [$oldRef, $newRef, $refName] = explode(" ", $line);
        if ($refName !== REF_NAME) {
            continue;
        }
        $commitMsg = `git log -1 --pretty=format:'%s' "$newRef"`;
        $cmdDiff = `git diff-tree --no-commit-id --name-only -r "$newRef"`;
        foreach (explode("\n", $cmdDiff) as $filePath) {
            $filePath = trim($filePath);
            if (!$filePath) {
                continue;
            }
            $changedFiles[] = $filePath;
        }
    }

    $firstCommit = $oldRef === '0000000000000000000000000000000000000000';

    $changedCharts = [];
    $changedHelms = [];
    $changedK8s = [];
    $allNamespaces = [];
    foreach ($changedFiles as $changedFile) {
        if (strpos($changedFile, "helm/charts/") === 0) {
            [, , $name] = explode("/", $changedFile);
            $changedCharts[$name] = $name;
        } elseif (strpos($changedFile, "helm/deployments/") === 0) {
            [, , $namespace, $name, $chart] = explode("/", $changedFile);
            $allNamespaces[$namespace] = $namespace;
            if (!array_key_exists($namespace, $changedHelms)) {
                $changedHelms[$namespace] = [];
            }
            if (!array_key_exists($name, $changedHelms[$namespace])) {
                $changedHelms[$namespace][$name] = [];
            }
            $chartName = substr($chart, 0, -5);
            $changedHelms[$namespace][$name][$chartName] = $chartName;
        } elseif (strpos($changedFile, "k8s/deployments/") === 0) {
            [, , $namespace] = explode("/", $changedFile);
            $allNamespaces[$namespace] = $namespace;
            if (!array_key_exists($namespace, $changedK8s)) {
                $changedK8s[$namespace] = [];
            }
            $changedK8s[$namespace][$changedFile] = $changedFile;
        }
    }

    foreach ($allNamespaces as $namespace) {
        $execCmd("kubectl create namespace ${namespace}");
    }

    $oldPath = $PATH_CLONE . '/old';
    if (!$firstCommit) {
        `git clone -q ${PATH_REPO} ${oldPath} && git --git-dir=${oldPath}/.git checkout -q -f ${oldRef}`;
    }

    $newPath = $PATH_CLONE . '/new';
    `git clone -q ${PATH_REPO} ${newPath} && git --git-dir=${newPath}/.git checkout -q -f ${newRef}`;

    foreach ($changedCharts as $changedChart) {
        foreach (glob("${newPath}/helm/deployments/*", GLOB_ONLYDIR) as $namespacePath) {
            $namespace = basename($namespacePath);
            foreach (glob("${namespacePath}/*") as $namePath) {
                if (is_file($namePath . "/${changedChart}.yaml")) {
                    $name = basename($namePath);
                    $changedHelms[$namespace][$name][$changedChart] = $changedChart;
                }
            }
        }
    }

//    var_dump([
//        'charts' => $changedCharts,
//        'helms' => $changedHelms,
//        'k8s' => $changedK8s,
//    ]);

    foreach ($changedHelms as $namespace => $changedHelm) {
        foreach ($changedHelm as $name => $chartNames) {
            foreach ($chartNames as $chartName) {
                $chartPath = "${newPath}/helm/charts/${chartName}";
                $valuesPath = "${newPath}/helm/deployments/${namespace}/${name}/${chartName}.yaml";
                if (!file_exists($valuesPath)) {
                    $execCmd("helm delete --namespace ${namespace} ${name}");
                    continue;
                }
                $execCmd(
                    "helm upgrade --install --create-namespace " .
                    "--namespace ${namespace} " .
                    "${name} " .
                    "${chartPath} " .
                    "-f ${valuesPath}"
                );
            }
        }
    }

    foreach ($changedK8s as $namespace => $changedFiles) {
        foreach ($changedFiles as $changedFile) {
            $newFile = "${newPath}/$changedFile";
            if (!file_exists($newFile) && !$firstCommit) {
                $oldFile = "${oldPath}/$changedFile";
                $execCmd(
                    "kubectl delete --namespace ${namespace} -f ${oldFile}"
                );
                continue;
            }
            $execCmd(
                "kubectl apply --namespace ${namespace} -f ${newFile}"
            );
        }
    }

} catch (\Throwable $e) {
    $error = $e;
}

echo shell_exec("rm -fr " . $PATH_CLONE);

if ($error instanceof \Throwable) {
    throw $error;
}
