param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot ".." )).Path,
    [string]$OutputDir = "",
    [string]$ZipName = "360tranquilite.zip"
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = Join-Path $ProjectRoot "dist"
}

$stagingRoot = Join-Path $OutputDir "staging"
$pluginFolder = "360tranquilite"
$stagingPluginDir = Join-Path $stagingRoot $pluginFolder

if (Test-Path $stagingRoot) {
    Remove-Item -Recurse -Force $stagingRoot
}

New-Item -ItemType Directory -Path $stagingPluginDir -Force | Out-Null
New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null

$excludeNames = @(
    ".git",
    ".github",
    "dist",
    "scripts"
)

Get-ChildItem -Force $ProjectRoot | ForEach-Object {
    if ($excludeNames -contains $_.Name) {
        return
    }

    $destination = Join-Path $stagingPluginDir $_.Name

    if ($_.PSIsContainer) {
        Copy-Item -Path $_.FullName -Destination $destination -Recurse -Force
    } else {
        Copy-Item -Path $_.FullName -Destination $destination -Force
    }
}

$zipPath = Join-Path $OutputDir $ZipName
if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$fileList = Get-ChildItem -Path $stagingRoot -Recurse -File
$zipFileStream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::Create)
try {
    $zipArchive = New-Object System.IO.Compression.ZipArchive($zipFileStream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
    try {
        foreach ($file in $fileList) {
            $relativePath = $file.FullName.Substring($stagingRoot.Length).TrimStart([char[]]@([char]'\', [char]'/'))
            $entryPath = $relativePath -replace '\\', '/'
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zipArchive,
                $file.FullName,
                $entryPath,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    }
    finally {
        $zipArchive.Dispose()
    }
}
finally {
    $zipFileStream.Dispose()
}

$hash = (Get-FileHash -Algorithm SHA256 $zipPath).Hash
Write-Host "ZIP généré : $zipPath"
Write-Host "SHA256     : $hash"

Remove-Item -Recurse -Force $stagingRoot
