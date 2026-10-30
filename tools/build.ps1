param([string]$OutputDirectory)
$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
if (!$OutputDirectory) { $OutputDirectory = Join-Path $pluginRoot 'dist' }
$OutputDirectory = [IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$main = [IO.File]::ReadAllText((Join-Path $pluginRoot 'threadtandem-sync-for-telegram.php'))
$version = [regex]::Match($main, '(?m)^\s*\* Version:\s*([^\s]+)').Groups[1].Value
if (!$version) { throw 'Plugin version not found' }
$stageRoot = Join-Path $OutputDirectory ('build-' + $version + '-' + [guid]::NewGuid().ToString('N').Substring(0,8))
$packageRoot = Join-Path $stageRoot 'threadtandem-sync-for-telegram'
New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null
# An allowlist prevents tests, credentials and development tools entering the release.
foreach ($file in @('threadtandem-sync-for-telegram.php','uninstall.php','readme.txt','LICENSE')) {
    Copy-Item -LiteralPath (Join-Path $pluginRoot $file) -Destination $packageRoot
}
foreach ($directory in @('includes','assets')) {
    Copy-Item -LiteralPath (Join-Path $pluginRoot $directory) -Destination $packageRoot -Recurse
}
$languageRoot = Join-Path $packageRoot 'languages'
New-Item -ItemType Directory -Path $languageRoot -Force | Out-Null
# Runtime translations are checked into source; rebuild them from PO after editing translations.
$editorHash = [BitConverter]::ToString([Security.Cryptography.MD5]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes('assets/editor.js'))).Replace('-','').ToLowerInvariant()
foreach ($locale in @('ru_RU','en_US')) {
    foreach ($language in @("threadtandem-sync-for-telegram-$locale.po","threadtandem-sync-for-telegram-$locale.mo","threadtandem-sync-for-telegram-$locale-$editorHash.json")) {
        Copy-Item -LiteralPath (Join-Path $pluginRoot ('languages/' + $language)) -Destination $languageRoot
    }
}
$files = Get-ChildItem -LiteralPath $packageRoot -File -Recurse
foreach ($file in $files) {
    if ($file.Extension -ne '.mo' -and [IO.File]::ReadAllText($file.FullName) -match '(?<![A-Za-z0-9])\d{6,14}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9])') {
        throw ('Possible embedded bot credential: ' + $file.Name)
    }
}
$archivePath = Join-Path $OutputDirectory ('threadtandem-sync-for-telegram-' + $version + '.zip')
Compress-Archive -LiteralPath $packageRoot -DestinationPath $archivePath -Force
$hash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
($hash + '  ' + [IO.Path]::GetFileName($archivePath)) | Set-Content -LiteralPath ($archivePath + '.sha256') -Encoding ascii
[pscustomobject]@{Archive=$archivePath;SHA256=$hash;Files=$files.Count;Staging=$packageRoot}
