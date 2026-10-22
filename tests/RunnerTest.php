<?php

declare(strict_types=1);

function test_runner(): void
{
    T::group('dev runner: a failed check cannot be hidden by a later success');
    $box = sys_get_temp_dir() . '/ts-runner-' . bin2hex(random_bytes(4));
    mkdir("$box/bin", 0755, true);
    mkdir("$box/dev");
    copy(__DIR__ . '/../dev/test.sh', "$box/dev/test.sh");
    $scripts = [
        'docker' => <<<'SH'
#!/bin/sh
if [ "$1" = run ]; then
  mode=-c
  for arg do
    case "$arg" in -c|-ec) mode="$arg" ;; esac
    command="$arg"
  done
  if [ "$command" = 'sh /plugin/tests/mutate.sh' ]; then
    echo mutate >> "$RUNNER_LOG"
    [ "$RUNNER_FAIL" != mutate ]
  else
    exec sh "$mode" "$command"
  fi
else
  stage="$(basename "$4" .sh)"
  echo "$stage" >> "$RUNNER_LOG"
  [ "$RUNNER_FAIL" != "$stage" ]
fi
SH,
        'find' => "#!/bin/sh\necho /plugin/tests/example.php\n",
        'php' => <<<'SH'
#!/bin/sh
case "$*" in *tests/run.php*) stage=unit ;; *) stage=lint ;; esac
echo "$stage" >> "$RUNNER_LOG"
[ "$RUNNER_FAIL" != "$stage" ] || exit 7
[ "$stage" != lint ] || echo 'No syntax errors detected'
exit 0
SH,
        'python3' => <<<'SH'
#!/bin/sh
stage="$(basename "$1" .py)"
echo "$stage" >> "$RUNNER_LOG"
[ "$RUNNER_FAIL" != "$stage" ]
SH,
    ];
    foreach ($scripts as $name => $script) {
        file_put_contents("$box/bin/$name", $script . "\n");
        chmod("$box/bin/$name", 0755);
    }
    foreach (['test-patch', 'test-port-recolour'] as $stage) {
        file_put_contents("$box/dev/$stage.sh", "#!/bin/sh\necho $stage >> \"\$RUNNER_LOG\"\n[ \"\$RUNNER_FAIL\" != $stage ]\n");
    }
    $run = function (string $mode, string $failure = '') use ($box): array {
        file_put_contents("$box/log", '');
        $out = [];
        $env = 'PATH=' . escapeshellarg("$box/bin:" . getenv('PATH'))
            . ' RUNNER_LOG=' . escapeshellarg("$box/log") . ' RUNNER_FAIL=' . escapeshellarg($failure);
        exec('env ' . $env . ' sh ' . escapeshellarg("$box/dev/test.sh") . ' ' . escapeshellarg($mode) . ' 2>&1', $out, $code);

        return [$code, (string) file_get_contents("$box/log"), implode("\n", $out)];
    };
    try {
        foreach (['unit', 'all'] as $mode) {
            [$code, , $out] = $run($mode);
            T::ok("$mode succeeds when every check succeeds", $code === 0, $out);
            foreach (['lint', 'unit', 'gen-token-catalog', 'gen-token-docs', 'make-clock-tower', 'make-paisley', 'upstream-drift'] as $stage) {
                [$code, , $out] = $run($mode, $stage);
                T::ok("$mode reports a failure in $stage", $code !== 0, $out);
            }
        }
        foreach (['live', 'all'] as $mode) {
            foreach (['test-patch', 'test-graphs', 'test-port-recolour', 'test-picker', 'test-upload'] as $stage) {
                [$code, $log, $out] = $run($mode, $stage);
                T::ok("$mode reports a failure in $stage and stops the live suites", $code !== 0 && ($stage === 'test-upload' || ! str_contains($log, 'test-upload')), $out . "\n" . $log);
            }
        }
        [$code, , $out] = $run('all', 'mutate');
        T::ok('all reports a mutation-check failure', $code !== 0, $out);
    } finally {
        exec('rm -rf ' . escapeshellarg($box));
    }
}
