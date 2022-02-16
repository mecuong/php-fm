<?php

define('PATCH_DIFF_URL', 'https://api.github.com/repos/:owner/:repo/pulls/:pull_request?state=all');

$owner = $argv[1] ?? '';
$repo = $argv[2] ?? '';
$token = $argv[3] ?? '';
$pull_request = $argv[4] ?? '';

if (!$pull_request || !$token || !$repo || !$owner) {
    echo "Usage: php finddiff.php <owner> <repo> <token> <pull_request>\n";
    exit(1);
}

$patch_diff_url = str_replace(
    [':owner', ':repo', ':pull_request'],
    [$owner, $repo, $pull_request],
    PATCH_DIFF_URL
);

$context = stream_context_create([
    'http' => [
        'method'=>"GET",
        'header' => "Authorization: token $token\r\n" .
            "Accept: application/vnd.github.v3.diff\r\n" .
            "User-Agent: oikura\r\n",
    ],
]);


$diff_content = file_get_contents($patch_diff_url, false, $context);
preg_match_all('/diff.\-\-git.a\/([a-zA-Z\_\-\/\.]+\.php)/', $diff_content, $file_matches);

echo join(PHP_EOL, $file_matches[1] ?? []) . PHP_EOL;
