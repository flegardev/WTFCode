[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-InstallerCondition {
    param(
        [Parameter(Mandatory = $true)]
        [bool] $Condition,

        [Parameter(Mandatory = $true)]
        [string] $Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

function Get-Sha256 {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path
    )

    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Copy-ExpectedZipExecutable {
    param(
        [Parameter(Mandatory = $true)]
        [string] $ArchivePath,

        [Parameter(Mandatory = $true)]
        [string] $ExecutableName,

        [Parameter(Mandatory = $true)]
        [string] $DestinationPath
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($ArchivePath)
    try {
        $matches = @(
            $archive.Entries | Where-Object {
                -not [string]::IsNullOrWhiteSpace($_.Name) -and
                [string]::Equals($_.Name, $ExecutableName, [System.StringComparison]::OrdinalIgnoreCase)
            }
        )

        Assert-InstallerCondition ($matches.Count -eq 1) "Archive must contain exactly one $ExecutableName entry; found $($matches.Count)."

        $inputStream = $matches[0].Open()
        try {
            $outputStream = [System.IO.File]::Open(
                $DestinationPath,
                [System.IO.FileMode]::CreateNew,
                [System.IO.FileAccess]::Write,
                [System.IO.FileShare]::None
            )
            try {
                $inputStream.CopyTo($outputStream)
            } finally {
                $outputStream.Dispose()
            }
        } finally {
            $inputStream.Dispose()
        }
    } finally {
        $archive.Dispose()
    }
}

$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$manifestPath = Join-Path $repositoryRoot 'config\tool-manifest.json'
$binaryDirectory = Join-Path $repositoryRoot 'tools\bin'

Assert-InstallerCondition (Test-Path -LiteralPath $manifestPath -PathType Leaf) 'The pinned tool manifest is missing.'

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
Assert-InstallerCondition ($null -ne $manifest.tools) 'The tool manifest has no tools collection.'

$definitions = @{
    'Gitleaks' = [pscustomobject]@{
        Repository = 'gitleaks/gitleaks'
        Executable = 'gitleaks.exe'
        VersionArguments = @('version')
        Packaging = 'zip'
    }
    'OSV-Scanner' = [pscustomobject]@{
        Repository = 'google/osv-scanner'
        Executable = 'osv-scanner.exe'
        VersionArguments = @('--version')
        Packaging = 'direct'
    }
    'Syft' = [pscustomobject]@{
        Repository = 'anchore/syft'
        Executable = 'syft.exe'
        VersionArguments = @('version')
        Packaging = 'zip'
    }
    'Grype' = [pscustomobject]@{
        Repository = 'anchore/grype'
        Executable = 'grype.exe'
        VersionArguments = @('version')
        Packaging = 'zip'
    }
}

$tools = @($manifest.tools)
Assert-InstallerCondition ($tools.Count -eq $definitions.Count) "Expected exactly $($definitions.Count) pinned Windows tools; found $($tools.Count)."

$manifestNames = @($tools | ForEach-Object { [string] $_.name })
Assert-InstallerCondition (($manifestNames | Select-Object -Unique).Count -eq $manifestNames.Count) 'Duplicate tool names are not allowed in the manifest.'
foreach ($expectedName in $definitions.Keys) {
    Assert-InstallerCondition ($manifestNames -contains $expectedName) "The manifest is missing $expectedName."
}

$tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$workDirectory = [System.IO.Path]::GetFullPath(
    [System.IO.Path]::Combine($tempRoot, 'wtfcode-security-tools-' + [guid]::NewGuid().ToString('N'))
)
$tempRootPrefix = $tempRoot.TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
$workLeaf = [System.IO.Path]::GetFileName($workDirectory)
Assert-InstallerCondition ($workDirectory.StartsWith($tempRootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) 'Refusing to use a work directory outside the system temp directory.'
Assert-InstallerCondition ($workLeaf.StartsWith('wtfcode-security-tools-', [System.StringComparison]::Ordinal)) 'Refusing to use an unexpected work directory.'

$workDirectoryCreated = $false
$incomingPaths = [System.Collections.Generic.List[string]]::new()
$installed = [System.Collections.Generic.List[object]]::new()

try {
    New-Item -ItemType Directory -Path $workDirectory -ErrorAction Stop | Out-Null
    $workDirectoryCreated = $true
    New-Item -ItemType Directory -Path $binaryDirectory -Force -ErrorAction Stop | Out-Null

    foreach ($tool in $tools) {
        $name = [string] $tool.name
        $version = [string] $tool.version
        $source = [string] $tool.source
        $artifact = [string] $tool.artifact
        $artifactSha256 = ([string] $tool.artifact_sha256).ToLowerInvariant()
        $executableSha256 = ([string] $tool.executable_sha256).ToLowerInvariant()
        $definition = $definitions[$name]

        Assert-InstallerCondition ($version -match '^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$') "$name has an invalid pinned version."
        Assert-InstallerCondition ($artifact -match '^[A-Za-z0-9._-]+$') "$name has an unsafe artifact name."
        Assert-InstallerCondition ([System.IO.Path]::GetFileName($artifact) -eq $artifact) "$name artifact must be a filename, not a path."
        Assert-InstallerCondition ($artifactSha256 -match '^[a-f0-9]{64}$') "$name has an invalid artifact SHA-256."
        Assert-InstallerCondition ($executableSha256 -match '^[a-f0-9]{64}$') "$name has an invalid executable SHA-256."

        $sourceUri = [uri] $source
        Assert-InstallerCondition ($sourceUri.Scheme -eq 'https' -and $sourceUri.Host -eq 'github.com') "$name source must be an HTTPS github.com release URL."
        Assert-InstallerCondition ([string]::IsNullOrEmpty($sourceUri.Query) -and [string]::IsNullOrEmpty($sourceUri.Fragment)) "$name source URL must not contain a query or fragment."

        $segments = @($sourceUri.AbsolutePath.Trim('/') -split '/')
        Assert-InstallerCondition ($segments.Count -eq 5) "$name source URL does not identify one GitHub release tag."
        $repository = $segments[0] + '/' + $segments[1]
        $tag = [uri]::UnescapeDataString($segments[4])
        Assert-InstallerCondition ($repository -ceq $definition.Repository) "$name source repository is not the approved official repository."
        Assert-InstallerCondition ($segments[2] -ceq 'releases' -and $segments[3] -ceq 'tag') "$name source URL is not a release-tag URL."
        Assert-InstallerCondition ($tag -ceq ('v' + $version)) "$name release tag does not match its pinned version."

        $escapedTag = [uri]::EscapeDataString($tag)
        $escapedArtifact = [uri]::EscapeDataString($artifact)
        $assetUri = "https://github.com/$repository/releases/download/$escapedTag/$escapedArtifact"
        $artifactPath = Join-Path $workDirectory ($name.ToLowerInvariant().Replace('-', '') + '-' + $artifact)
        $stagedExecutable = Join-Path $workDirectory ($name.ToLowerInvariant().Replace('-', '') + '-' + $definition.Executable)

        Write-Host "Downloading $name $version from $assetUri"
        Invoke-WebRequest -Uri $assetUri -OutFile $artifactPath -UseBasicParsing -Headers @{ 'User-Agent' = 'WTFCode-security-tool-installer' }

        $downloadHash = Get-Sha256 $artifactPath
        Assert-InstallerCondition ($downloadHash -ceq $artifactSha256) "$name artifact SHA-256 mismatch. Expected $artifactSha256; received $downloadHash."

        if ($definition.Packaging -eq 'zip') {
            Assert-InstallerCondition ($artifact.EndsWith('.zip', [System.StringComparison]::OrdinalIgnoreCase)) "$name must use its pinned ZIP artifact."
            Copy-ExpectedZipExecutable -ArchivePath $artifactPath -ExecutableName $definition.Executable -DestinationPath $stagedExecutable
        } elseif ($definition.Packaging -eq 'direct') {
            Assert-InstallerCondition ($artifact.EndsWith('.exe', [System.StringComparison]::OrdinalIgnoreCase)) "$name must use its pinned executable artifact."
            [System.IO.File]::Copy($artifactPath, $stagedExecutable, $false)
        } else {
            throw "$name has an unsupported packaging type."
        }

        $stagedHash = Get-Sha256 $stagedExecutable
        Assert-InstallerCondition ($stagedHash -ceq $executableSha256) "$name executable SHA-256 mismatch. Expected $executableSha256; received $stagedHash."

        $destination = Join-Path $binaryDirectory $definition.Executable
        $incoming = $destination + '.incoming-' + [guid]::NewGuid().ToString('N')
        $incomingPaths.Add($incoming)
        [System.IO.File]::Copy($stagedExecutable, $incoming, $false)
        Assert-InstallerCondition ((Get-Sha256 $incoming) -ceq $executableSha256) "$name incoming executable failed its final integrity check."
        Move-Item -LiteralPath $incoming -Destination $destination -Force

        $finalHash = Get-Sha256 $destination
        Assert-InstallerCondition ($finalHash -ceq $executableSha256) "$name installed executable failed its final SHA-256 check."

        $versionArguments = [string[]] $definition.VersionArguments
        $versionOutput = & $destination @versionArguments 2>&1
        $versionExitCode = $LASTEXITCODE
        $versionText = ($versionOutput | Out-String).Trim()
        Assert-InstallerCondition ($versionExitCode -eq 0) "$name version check exited with code $versionExitCode."
        Assert-InstallerCondition ($versionText -match [regex]::Escape($version)) "$name version output did not include the pinned version $version."

        $installed.Add([pscustomobject]@{
            Tool = $name
            Version = $version
            Sha256 = $finalHash
            VersionCheck = ($versionText -replace '\r?\n', ' | ')
        })
    }

    $installed | Format-Table -AutoSize
    Write-Host "Installed and verified $($installed.Count) pinned security tools in $binaryDirectory"
} finally {
    foreach ($incomingPath in $incomingPaths) {
        if (Test-Path -LiteralPath $incomingPath) {
            Remove-Item -LiteralPath $incomingPath -Force
        }
    }

    if ($workDirectoryCreated -and (Test-Path -LiteralPath $workDirectory)) {
        $resolvedWorkDirectory = [System.IO.Path]::GetFullPath((Get-Item -LiteralPath $workDirectory).FullName)
        $resolvedLeaf = [System.IO.Path]::GetFileName($resolvedWorkDirectory)
        Assert-InstallerCondition ($resolvedWorkDirectory -ceq $workDirectory) 'Refusing to clean a work directory whose resolved path changed.'
        Assert-InstallerCondition ($resolvedWorkDirectory.StartsWith($tempRootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) 'Refusing to clean outside the system temp directory.'
        Assert-InstallerCondition ($resolvedLeaf.StartsWith('wtfcode-security-tools-', [System.StringComparison]::Ordinal)) 'Refusing to clean an unexpected directory.'
        Remove-Item -LiteralPath $resolvedWorkDirectory -Recurse -Force
    }
}
