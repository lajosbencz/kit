<?php

error_reporting(E_ERROR);

require_once __DIR__ . '/utils.php';

const REF_NAME = 'refs/heads/master';
const LOCK_RETRY = 100;
const LOCK_WAIT = 3;

parse_env("/var/kit/env");

$PATH_KIT = getenv('PATH_KIT') ?: '/var/kit';
$PATH_PVC = getenv('PATH_PVC') ?: $PATH_KIT . '/pvc';
$PATH_REPO = getenv('PATH_REPO') ?: $PATH_PVC . '/kit.git';
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
    echo "$cmd", PHP_EOL;
//    echo "result>", PHP_EOL;
    $stdout = '';
    $stderr = '';
    $code = exec_cmd($cmd, $stdout, $stderr);
    echo $stdout;
    if ($code <> 0) {
        throw new RuntimeException($stderr ?: $stdout);
    }
//    echo "<result", PHP_EOL;
};

$error = null;
try {
    $stdin = fgets(STDIN);

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
        $cmdDiff = `git show --pretty="" --name-only "$newRef"`;
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
        try {
            $execCmd("kubectl create namespace ${namespace}");
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'AlreadyExists') === false) {
                throw $e;
            }
        }
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
                    try {
                        $execCmd("helm delete --namespace ${namespace} ${name}");
                    } catch (\Throwable $e) {
                        echo "failed to delete helm deployment {$chartName}:${name}: ", $e->getMessage(), PHP_EOL;
                    }
                    continue;
                }
                try {
                    $execCmd(
                        "helm upgrade --install --create-namespace " .
                        "--namespace ${namespace} " .
                        "${name} " .
                        "${chartPath} " .
                        "-f ${valuesPath}"
                    );
                } catch (\Throwable $e) {
                    echo "failed deploy helm {$chartName}:${name}: ", $e->getMessage(), PHP_EOL;
                }
            }
        }
    }

    foreach ($changedK8s as $namespace => $changedFiles) {
        foreach ($changedFiles as $changedFile) {
            $newFile = "${newPath}/$changedFile";
            if (!file_exists($newFile) && !$firstCommit) {
                $oldFile = "${oldPath}/$changedFile";
                try {
                    $execCmd(
                        "kubectl delete --namespace ${namespace} -f ${oldFile}"
                    );
                } catch (\Throwable $e) {
                    echo $e->getMessage(), PHP_EOL;
                }
                continue;
            }
            try {
                $execCmd(
                    "kubectl apply --namespace ${namespace} -f ${newFile}"
                );
            } catch (\Throwable $e) {
                echo $e->getMessage(), PHP_EOL;
            }
        }
    }

} catch (\Throwable $e) {
    $error = $e;
}

try {
    $execCmd("rm -fr " . $PATH_CLONE);
} catch (\Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}

if ($error instanceof \Throwable) {
    throw $error;
}
