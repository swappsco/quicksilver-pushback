<?php

include __DIR__ . '/lean-repo-utils.php';

// Do nothing if not on Pantheon or if on the test/live environments.
if (!isset($_ENV['PANTHEON_ENVIRONMENT']) || in_array($_ENV['PANTHEON_ENVIRONMENT'], ['test', 'live']) ) {
    return;
}

/**
 * This script will separates changes from the most recent commit
 * and pushes any that affect the canonical sources back to the
 * master repository.
 */
$bindingDir = $_SERVER['HOME'];
$fullRepository = realpath("$bindingDir/code");
// $docRoot = "$fullRepository/" . $_SERVER['DOCROOT'];

print "Enter push-back. Repository root is $fullRepository.\n";

$privateFiles = realpath("$bindingDir/files/private");
$gitSecretsFile = "$privateFiles/.build-secrets/tokens.json";
$gitSecrets = load_git_secrets($gitSecretsFile);
$git_token = $gitSecrets['token'];

if (empty($git_token)) {
    $message = "Unable to load Git token from secrets file";
    pantheon_raise_dashboard_error($message, true);
}

$workDir = sys_get_temp_dir() . "/pushback-workdir";

// Temporary:
passthru("rm -rf $workDir");
mkdir($workDir);

// Updating metadata with pantheon repository data.
$pantheon_remoteHead = exec("git -C $fullRepository rev-parse HEAD");
$pantheon_commit = exec("git -C $fullRepository log -1 --pretty=\"%s\"");
$pantheon_commit_date = exec("git -C $fullRepository log -1 --pretty='%ci'");
$pantheon_build_date = date("Y-m-d H:i:s O");

$currentDateTime = new DateTime();
$interval = new DateInterval('PT10M');
$currentDateTime->sub($interval);
$pantheon_build_date = $currentDateTime->format("Y-m-d H:i:s O");

$fake_metadata = array(
    "url" => "git@github.com:swappsco/ncarb.git",
    "ref" => "master",
    "sha" => $pantheon_remoteHead ,
    "comment" => $pantheon_commit,
    "commit-date" => $pantheon_commit_date,
    "build-date" => $pantheon_build_date,
);

$jsonData = json_encode($fake_metadata, JSON_PRETTY_PRINT);
$fake_build_metadata_path = $fullRepository."/build-metadata.json";
file_put_contents($fake_build_metadata_path, $jsonData);

// Getting Metadata & Providers
$buildProviders = load_build_providers($fullRepository);
$buildMetadata = load_build_metadata($fullRepository);
// The remote repo to push to
$upstreamRepo = $buildMetadata['url'];
$upstreamRepoWithCredentials = $upstreamRepo;
if (isset($buildProviders['git'])) {
    switch ($buildProviders['git']) {
        case 'github':
            $upstreamRepoWithCredentials = str_replace('git@github.com:', 'https://github.com/', $upstreamRepoWithCredentials);
            $upstreamRepoWithCredentials = str_replace('https://', "https://$git_token:x-oauth-basic@", $upstreamRepoWithCredentials);
            break;

        case 'gitlab':
            // While initial Git URLs from Build Tools are SSH based, they are immediately replaced
            // by the HTTP ones from GitLab CI. This runs at initial setup so the SSH one shouldn't
            // be there very long.
            if ((strpos($upstreamRepoWithCredentials, 'https://') !== false) || (strpos($upstreamRepoWithCredentials, 'http://') !== false)) {
                $parsed_url = parse_url($upstreamRepoWithCredentials);
                $parsed_url['user'] = 'oauth2';
                $parsed_url['pass'] = $git_token;
                $upstreamRepoWithCredentials = http_build_url($parsed_url);
            }
            else {
                pantheon_raise_dashboard_error("Error parsing GitLab URL from Build Metadata.", true);
            }
            break;

        case 'bitbucket':
            $upstreamRepoWithCredentials = str_replace('git@bitbucket.org:', 'https://bitbucket.org/', $upstreamRepoWithCredentials);
            if ((strpos($upstreamRepoWithCredentials, 'https://') !== false) || (strpos($upstreamRepoWithCredentials, 'http://') !== false)) {
                $parsed_url = parse_url($upstreamRepoWithCredentials);
                $parsed_url['user'] = $gitSecrets['user'];
                $parsed_url['pass'] = $gitSecrets['pass'];
                $upstreamRepoWithCredentials = http_build_url($parsed_url);
            }
            else {
                pantheon_raise_dashboard_error("Error parsing Bitbucket URL from Build Metadata.", true);
            }
            break;

        default:

    }
}

$status = push_back($fullRepository, $workDir, $upstreamRepoWithCredentials, $buildMetadata, "build-metadata.json");

// Throw out the working repository.
passthru("rm -rf $workDir");

// Post error to dashboard and exit if the merge fails.
if ($status != 0) {
    $message = "Commit back to canonical repository failed with exit code $status.";
    pantheon_raise_dashboard_error($message, true);
}
