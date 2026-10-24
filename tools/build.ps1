param([string]$OutputDirectory)
$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
if (!$OutputDirectory) { $OutputDirectory = Join-Path $pluginRoot 'dist' }
$OutputDirectory = [IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$main = [IO.File]::ReadAllText((Join-Path $pluginRoot 'treda.php'))
$version = [regex]::Match($main, '(?m)^\s*\* Version:\s*([^\s]+)').Groups[1].Value
if (!$version) { throw 'Plugin version not found' }
$stageRoot = Join-Path $OutputDirectory ('build-' + $version + '-' + [guid]::NewGuid().ToString('N').Substring(0,8))
$packageRoot = Join-Path $stageRoot 'treda'
New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null
# An allowlist prevents tests, credentials and development tools entering the release.
foreach ($file in @('treda.php','uninstall.php','readme.txt','LICENSE')) {
    Copy-Item -LiteralPath (Join-Path $pluginRoot $file) -Destination $packageRoot
}
foreach ($directory in @('includes','assets','languages')) {
    Copy-Item -LiteralPath (Join-Path $pluginRoot $directory) -Destination $packageRoot -Recurse
}
$files = Get-ChildItem -LiteralPath $packageRoot -File -Recurse
foreach ($file in $files) {
    if ([IO.File]::ReadAllText($file.FullName) -match '(?<![A-Za-z0-9])\d{6,14}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9])') {
        throw ('Possible embedded bot credential: ' + $file.Name)
    }
}
$archivePath = Join-Path $OutputDirectory ('treda-' + $version + '.zip')
Compress-Archive -LiteralPath $packageRoot -DestinationPath $archivePath -Force
$hash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
($hash + '  ' + [IO.Path]::GetFileName($archivePath)) | Set-Content -LiteralPath ($archivePath + '.sha256') -Encoding ascii
[pscustomobject]@{Archive=$archivePath;SHA256=$hash;Files=$files.Count;Staging=$packageRoot}
